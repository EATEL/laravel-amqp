<?php

namespace Rev\Amqp;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPSocketException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Rev\Amqp\Contracts\Amqp as AmqpContract;
use Rev\Amqp\Events\ConsumerStarted;
use Rev\Amqp\Events\ConsumerStopped;
use Rev\Amqp\Events\MessageFailed;
use Rev\Amqp\Events\MessageProcessed;
use Rev\Amqp\Events\MessagePublished;
use Rev\Amqp\Events\MessageReceived;
use Rev\Amqp\Support\ConnectionUrlParser;

class AmqpService implements AmqpContract
{
    /**
     * Connection instance
     */
    private ?AbstractConnection $connection = null;

    /**
     * Channel instances
     */
    private array $channels = [];

    /**
     * Connection configuration cache
     */
    private ?AMQPConnectionConfig $connectionConfig = null;

    /**
     * Retry attempts counter
     */
    private int $retryAttempts = 0;

    /**
     * Flag to indicate if the service should shutdown
     */
    private bool $shouldShutdown = false;

    /**
     * Track if signal handlers are registered
     */
    private bool $signalHandlersRegistered = false;

    /**
     * Retry configuration
     */
    private array $retryConfig;

    /**
     * Exceptions that should trigger a reconnection attempt
     */
    private array $reconnectionExceptions = [
        AMQPConnectionClosedException::class,
        AMQPHeartbeatMissedException::class,
        AMQPIOException::class,
        AMQPSocketException::class,
        AMQPTimeoutException::class,
        AMQPRuntimeException::class,
        \ErrorException::class,
    ];

    private array $defaultPublishOptions = [
        'mandatory' => false,
    ];

    public function __construct(
        protected readonly array $config,
    ) {
        $this->retryConfig = array_merge([
            'max_attempts' => 5,
            'initial_delay' => 1,    // seconds
            'max_delay' => 30,       // seconds
            'backoff_multiplier' => 2,
            'jitter' => true,        // add randomness to prevent thundering herd
        ], $config['retry'] ?? []);

        $this->setupCleanupHandlers();
    }


    private function defaultMessageProperties(array $messageProperties = []):  array {
        $messageId = Str::ulid();
        return array_merge([
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'timestamp' => now()->timestamp,
            'message_id' => $messageId,
        ], $messageProperties);
    }

    /**
     * Publish a message to an exchange
     *
     * @param mixed $payload The payload to publish (will be JSON encoded)
     * @param string $exchange The exchange name
     * @param string $routingKey The routing key
     * @param array $messageProperties Additional message properties
     * @param array $publishOptions Additional publish options
     * @return string The message id.  If a `message_id` is in the messageProperties, it will be used otherwise one will be generated 
     */
    public function publish(
        mixed $payload,
        string $exchange,
        string $routingKey = '',
        array $messageProperties = [],
        array $publishOptions = [],
    ): string {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $messageProperties = $this->defaultMessageProperties($messageProperties);
        $publishOptions = array_merge($this->defaultPublishOptions, $publishOptions);

        $message = new AMQPMessage($json, $messageProperties);

        $this->execute(function ($channel) use ($message, $exchange, $routingKey, $publishOptions, $payload, $messageProperties) {
            $channel->basic_publish($message, $exchange, $routingKey, $publishOptions['mandatory']);

            // Dispatch publish event
            Event::dispatch(new MessagePublished($payload, $exchange, $routingKey, $messageProperties['message_id'], $messageProperties, $publishOptions));
        });
        return $messageProperties['messageId'];
    }

    /**
     * Consume messages from a queue
     *
     * @param string $queue The queue name to consume from
     * @param callable $callback The callback function to handle messages
     * @param array $options Consumption options
     * @return void
     */
    public function consume(
        string $queue,
        callable $callback,
        array $options = [],
    ): void {
        $this->registerSignalHandlers();

        $defaultOptions = [
            'consumer_tag' => '',
            'no_local' => false,
            'no_ack' => false,
            'exclusive' => false,
            'nowait' => false,
            'heartbeat_check' => 30,
        ];

        $options = array_merge($defaultOptions, $options);

        Event::dispatch(new ConsumerStarted($queue, $options));

        $this->executeConsumer(function (AMQPChannel $channel) use ($queue, $callback, $options) {
            // Wrap the user callback to handle JSON decoding and return value
            $wrappedCallback = function (AMQPMessage $message) use ($callback, $options, $queue, $channel) {
                try {
                    // Check for shutdown signal before processing
                    if ($this->shouldShutdown) {
                        $this->log('info', "Shutdown signal received, cancelling consumer");
                        $channel->basic_cancel($message->getConsumerTag());
                        return;
                    }

                    // Get message body
                    $body = $message->getBody();
                    // Check content type and parse accordingly
                    $contentType = $message->get('content_type');
                    if ($contentType === 'application/json' || $contentType === null) {
                        try {
                            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            $this->log('error', "Failed to decode message JSON", [
                                'error' => $e->getMessage(),
                                'body' => $body,
                                'content_type' => $contentType
                            ]);
                            // Reject message - it will be discarded or sent to DLQ
                            $message->nack(requeue: false, multiple: false);
                            return;
                        }
                    } else {
                        // Return raw body string for non-JSON content
                        $payload = $body;
                    }

                    Event::dispatch(new MessageReceived($payload, $message, $queue));

                    // Call user callback with payload and message
                    $result = $callback($payload, $message);

                    Event::dispatch(new MessageProcessed($payload, $message, $queue, $result));

                    // If callback returns false, stop consuming
                    if ($result === false) {
                        // Cancel the consumer to stop the loop
                        $channel->basic_cancel($message->getConsumerTag());
                    }

                    // Auto-acknowledge message unless no_ack is true
                    if (!$options['no_ack']) {
                        $message->ack();
                    }
                } catch (\Throwable $e) {
                    Event::dispatch(new MessageFailed($payload ?? [], $message, $queue, $e));

                    $this->log('error', "Error processing message", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'queue' => $queue
                    ]);

                    // Reject and requeue message
                    $message->nack(requeue: false);
                }
            };

            // Start consuming
            $channel->basic_consume(
                queue: $queue,
                consumer_tag: $options['consumer_tag'],
                no_local: $options['no_local'],
                no_ack: $options['no_ack'],
                exclusive: $options['exclusive'],
                nowait: $options['nowait'],
                callback: $wrappedCallback
            );

            $this->log('info', "Started consuming from queue '$queue'");

            // Keep consuming until cancelled or shutdown
            while ($channel->is_consuming() && !$this->shouldShutdown) {
                // Use a timeout on wait() to allow periodic shutdown checks
                try {
                    $channel->wait(allowed_methods: null, non_blocking: false, timeout: 1);
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // Timeout is expected, continue the loop
                    continue;
                }
            }

            $this->log('info', "Stopped consuming from queue '$queue'");
        }, 'consumer', $options['heartbeat_check']);

        Event::dispatch(new ConsumerStopped($queue));
    }

    /**
     * Get connection statistics
     */
    public function getStats(): array
    {
        return [
            'connected' => $this->isConnectionHealthy(),
            'retry_attempts' => $this->retryAttempts,
        ];
    }

    /**
     * Close all connections
     */
    public function closeConnections(): void
    {
        $this->log('info', 'Closing AMQP connections');

        // Close all channels first
        foreach (array_keys($this->channels) as $channelId) {
            $this->closeChannel($channelId);
        }

        // Then close connection
        $this->closeConnection();

        $this->channels = [];
        $this->retryAttempts = 0;
    }

    // ===== PRIVATE METHODS (Internal Connection Management) =====

    /**
     * Get or create an AMQP connection with automatic reconnection
     */
    private function getConnection(): AbstractConnection
    {
        // Return existing healthy connection
        if ($this->connection !== null && $this->isConnectionHealthy()) {
            return $this->connection;
        }

        // Connection doesn't exist or is unhealthy - create/recreate it
        return $this->createConnectionWithRetry();
    }

    /**
     * Get or create an AMQP channel with automatic reconnection
     */
    private function getChannel(string $channelId = 'default'): AMQPChannel
    {
        // Return existing healthy channel
        if (isset($this->channels[$channelId]) && $this->isChannelHealthyInternal($channelId)) {
            return $this->channels[$channelId];
        }

        // Channel doesn't exist or is unhealthy - create/recreate it
        $connection = $this->getConnection();
        $channel = $connection->channel();

        $this->channels[$channelId] = $channel;
        $this->log('debug', "Created new AMQP channel: $channelId");

        return $channel;
    }

    /**
     * Check if channel is healthy (internal method)
     */
    private function isChannelHealthyInternal(string $channelId): bool
    {
        if (!isset($this->channels[$channelId])) {
            return false;
        }

        try {
            $channel = $this->channels[$channelId];

            // Check if channel is still open
            if (!$channel->is_open()) {
                $this->log('debug', "Channel $channelId is not open");
                return false;
            }

            // Check if the underlying connection is still healthy
            $connection = $channel->getConnection();
            if (!$connection->isConnected()) {
                $this->log('debug', "Channel $channelId connection is not connected");
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->log('debug', "Channel $channelId health check failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Check if connection is healthy
     */
    private function isConnectionHealthy(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            return $this->connection->isConnected();
        } catch (\Exception $e) {
            $this->log('debug', "AMQP connection health check failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Create connection with retry logic
     */
    private function createConnectionWithRetry(): AbstractConnection
    {
        $attempts = $this->retryAttempts;
        $maxAttempts = $this->retryConfig['max_attempts'];

        while ($attempts < $maxAttempts && !$this->shouldShutdown) {
            try {
                $connection = $this->createConnection();

                // Success - reset retry counter and cache connection
                $this->retryAttempts = 0;
                $this->connection = $connection;

                if ($attempts > 0) {
                    $this->log('info', "AMQP connection restored after $attempts attempts");
                }

                return $connection;
            } catch (\Exception $e) {
                $attempts++;
                $this->retryAttempts = $attempts;

                // Remove failed connection from cache
                $this->connection = null;

                if ($attempts >= $maxAttempts || $this->shouldShutdown) {
                    if ($this->shouldShutdown) {
                        throw new \RuntimeException("AMQP connection creation aborted due to shutdown signal");
                    }

                    $this->log('error', "AMQP connection failed after $maxAttempts attempts", [
                        'exception' => $e->getMessage(),
                        'attempts' => $attempts
                    ]);
                    throw $e;
                }

                $delay = $this->calculateBackoffDelay($attempts);
                $this->log('warning', "AMQP connection failed, retrying in {$delay}s (attempt $attempts/$maxAttempts)", [
                    'exception' => $e->getMessage()
                ]);

                // Sleep with shutdown check
                $this->sleepWithShutdownCheck($delay);
            }
        }

        throw new \RuntimeException("Failed to establish AMQP connection - shutdown requested");
    }

    /**
     * Calculate backoff delay with jitter
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        $delay = min(
            $this->retryConfig['initial_delay'] * pow($this->retryConfig['backoff_multiplier'], $attempt - 1),
            $this->retryConfig['max_delay']
        );

        // Add jitter to prevent thundering herd problem
        if ($this->retryConfig['jitter']) {
            $jitter = $delay * 0.1; // 10% jitter
            $delay += mt_rand(-$jitter * 100, $jitter * 100) / 100;
        }

        return max(1, (int) $delay);
    }

    /**
     * Create a new AMQP connection
     */
    private function createConnection(): AbstractConnection
    {
        // Cache config to avoid repeated config building
        if ($this->connectionConfig === null) {
            $this->connectionConfig = $this->buildConnectionConfig();
        }

        return AMQPConnectionFactory::create($this->connectionConfig);
    }

    /**
     * Build connection configuration
     */
    private function buildConnectionConfig(): AMQPConnectionConfig
    {
        $connConfig = $this->config['connection'];

        if (!empty($connConfig['url'])) {
            $parsedUrl = ConnectionUrlParser::parse($connConfig['url']);
            $connConfig = array_merge($connConfig, $parsedUrl);

            if (isset($parsedUrl['ssl'])) {
                $connConfig['ssl'] = array_merge(
                    $connConfig['ssl'] ?? [],
                    $parsedUrl['ssl']
                );
            }
        }

        $config = new AMQPConnectionConfig();
        $config->setHost($connConfig['host']);
        $config->setPort($connConfig['port']);
        $config->setUser($connConfig['user']);
        $config->setPassword($connConfig['password']);
        $config->setVhost($connConfig['vhost']);

        // Set connection and read timeouts for better failure detection
        $config->setConnectionTimeout($connConfig['connection_timeout'] ?? 10);
        $config->setReadTimeout($connConfig['read_timeout'] ?? 3);
        $config->setWriteTimeout($connConfig['write_timeout'] ?? 3);
        $config->setHeartbeat($connConfig['heartbeat'] ?? 10);

        // Handle SSL configuration
        if ($connConfig['ssl']['enabled'] ?? false) {
            $config->setIsSecure(true);
            if (isset($connConfig['ssl']['cafile'])) {
                $config->setSslCaCert($connConfig['ssl']['cafile']);
            }
            $config->setSslVerify($connConfig['ssl']['verify_peer'] ?? true);
        } else {
            $config->setIsSecure($this->shouldUseSecureConnection());
        }

        return $config;
    }

    /**
     * Determine if connection should be secure based on host
     */
    private function shouldUseSecureConnection(): bool
    {
        $host = $this->config['connection']['host'];
        return !in_array($host, ['localhost', '127.0.0.1']);
    }

    /**
     * Execute a callback with automatic reconnection using a channel
     */
    private function execute(callable $callback, string $channelId = 'default', ...$args)
    {
        $maxAttempts = $this->retryConfig['max_attempts'];
        $attempts = 0;

        while ($attempts < $maxAttempts && !$this->shouldShutdown) {
            try {
                $channel = $this->getChannel($channelId);
                return $callback($channel, ...$args);
            } catch (\Exception $e) {
                $attempts++;

                // Check if this is a connection-related exception
                if (!$this->shouldRetryException($e) || $attempts >= $maxAttempts || $this->shouldShutdown) {

                    // Check shutdown before throwing the final exception
                    if ($this->shouldShutdown) {
                        throw new \RuntimeException("AMQP operation aborted due to shutdown signal");
                    }

                    throw $e;
                }

                $this->log('warning', "AMQP operation failed, retrying (attempt $attempts/$maxAttempts)", [
                    'channel' => $channelId,
                    'exception' => $e->getMessage()
                ]);

                // Mark connection and channel as failed so they get recreated
                $this->closeChannel($channelId);
                $this->connection = null;

                $delay = $this->calculateBackoffDelay($attempts);

                // Sleep with shutdown check
                $this->sleepWithShutdownCheck($delay);
            }
        }

        // Check shutdown before throwing the final exception
        if ($this->shouldShutdown) {
            throw new \RuntimeException("AMQP operation aborted due to shutdown signal");
        }
        throw new \RuntimeException("AMQP operation failed after $maxAttempts attempts");
    }

    /**
     * Execute a consumer callback with automatic reconnection and heartbeat monitoring
     */
    private function executeConsumer(callable $callback, string $channelId = 'consumer', int $heartbeatCheck = 30, ...$args)
    {
        $lastHeartbeatCheck = time();

        while (!$this->shouldShutdown) {
            try {
                $channel = $this->getChannel($channelId);

                // Check connection health periodically during consumption
                if (time() - $lastHeartbeatCheck >= $heartbeatCheck) {
                    if (!$this->validateConnection(true) || !$this->isChannelHealthyInternal($channelId)) {
                        $this->log('warning', "AMQP connection/channel '$channelId' failed health check during consumption, reconnecting");
                        $this->closeChannel($channelId);
                        $this->closeConnection();
                        continue;
                    }
                    $lastHeartbeatCheck = time();
                }

                // Execute the consumer callback
                $result = $callback($channel, ...$args);

                // If callback returns false, exit the consumer loop
                if ($result === false) {
                    break;
                }
            } catch (\Exception $e) {
                // Check for shutdown before handling the error
                if ($this->shouldShutdown) {
                    $this->log('info', "AMQP consumer shutting down due to signal");
                    break;
                }

                $this->log('error', "AMQP consumer error", [
                    'channel' => $channelId,
                    'exception' => $e->getMessage(),
                    'type' => get_class($e)
                ]);

                // Close the failed channel and connection
                $this->closeChannel($channelId);
                $this->closeConnection();

                // Check if this is a retryable exception
                if (!$this->shouldRetryException($e)) {
                    throw $e;
                }

                $delay = $this->calculateBackoffDelay($this->retryAttempts + 1);
                $this->log('info', "Consumer will retry in {$delay}s");

                // Sleep with shutdown check
                $this->sleepWithShutdownCheck($delay);
            }
        }

        $this->log('info', "AMQP consumer loop exited");
    }

    /**
     * Check if exception should trigger a retry
     */
    private function shouldRetryException(\Exception $e): bool
    {
        foreach ($this->reconnectionExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        // Check for specific error messages that indicate network/connection issues
        $message = strtolower($e->getMessage());
        $networkErrors = [
            'broken pipe',
            'connection reset by peer',
            'ssl operation failed',
            'connection timed out',
            'network is unreachable',
            'no route to host',
            'connection refused',
            'connection aborted',
            'socket is not connected',
            'transport endpoint is not connected'
        ];

        foreach ($networkErrors as $error) {
            if (strpos($message, $error) !== false) {
                $this->log('debug', 'Detected network error, will retry', [
                    'exception' => $e->getMessage(),
                    'type' => get_class($e)
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Close a specific channel
     */
    private function closeChannel(string $channelId = 'default'): void
    {
        if (isset($this->channels[$channelId])) {
            try {
                if ($this->channels[$channelId]->is_open()) {
                    $this->channels[$channelId]->close();
                }
                unset($this->channels[$channelId]);
                $this->log('debug', "Closed AMQP channel: $channelId");
            } catch (\Exception $e) {
                $this->log('error', "Error closing AMQP channel $channelId", ['exception' => $e->getMessage()]);
                // Remove from cache anyway
                unset($this->channels[$channelId]);
            }
        }
    }

    /**
     * Close the connection
     */
    private function closeConnection(): void
    {
        // First close all channels
        foreach (array_keys($this->channels) as $channelId) {
            $this->closeChannel($channelId);
        }

        if ($this->connection !== null) {
            try {
                if ($this->connection->isConnected()) {
                    $this->connection->close();
                }
                $this->connection = null;
                $this->log('debug', "Closed AMQP connection");
            } catch (\Exception $e) {
                $this->log('error', "Error closing AMQP connection", ['exception' => $e->getMessage()]);
                // Remove from cache anyway
                $this->connection = null;
            }
        }

        // Reset retry counter
        $this->retryAttempts = 0;
    }

    /**
     * Validate connection with optional deep health check
     */
    private function validateConnection(bool $deepCheck = false): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            // Basic check - only checks internal connection state
            if (!$this->connection->isConnected()) {
                return false;
            }

            // Deep check - actually tests the network connection
            if ($deepCheck) {
                return $this->performDeepConnectionCheck();
            }

            return true;
        } catch (\Exception $e) {
            $this->log('debug', "AMQP connection validation failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Perform a deep connection check that actually tests network connectivity
     */
    private function performDeepConnectionCheck(): bool
    {
        try {
            // Test the connection by sending a heartbeat frame
            // This will fail immediately if there are SSL/network issues
            $this->connection->checkHeartBeat();

            return true;
        } catch (\Exception $e) {
            $this->log('debug', "AMQP deep connection check failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e)
            ]);

            // Mark connection as failed so it gets recreated
            $this->connection = null;

            return false;
        }
    }

    /**
     * Sleep with shutdown check
     */
    private function sleepWithShutdownCheck(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldShutdown; $i++) {
            sleep(1);
        }
    }

    /**
     * Set up all cleanup handlers (signals, shutdown, destructor)
     */
    private function setupCleanupHandlers(): void
    {
        // Register shutdown function (works for most termination scenarios)
        register_shutdown_function([$this, 'handleShutdown']);

        // Register signal handlers if PCNTL extension is available
        if (extension_loaded('pcntl')) {
            $this->registerSignalHandlers();
        }
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private function registerSignalHandlers(): void
    {
        if ($this->signalHandlersRegistered) {
            return;
        }

        // Handle SIGTERM (kill command)
        pcntl_signal(SIGTERM, [$this, 'handleSignal']);

        // Handle SIGINT (Ctrl+C)
        pcntl_signal(SIGINT, [$this, 'handleSignal']);

        // Handle SIGHUP (terminal closed)
        pcntl_signal(SIGHUP, [$this, 'handleSignal']);

        // Enable async signal handling (PHP 7.1+)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $this->signalHandlersRegistered = true;
    }

    /**
     * Handle Unix signals
     */
    public function handleSignal(int $signal): void
    {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP'
        ];

        $signalName = $signalNames[$signal] ?? "Signal $signal";
        $this->log('info', "AMQP Service received $signalName, initiating graceful shutdown");

        // Set shutdown flag to break out of consumer loops
        $this->shouldShutdown = true;

        // Close connections
        $this->closeConnections();
    }

    /**
     * Handle shutdown function (fallback for various termination scenarios)
     */
    public function handleShutdown(): void
    {
        // Check if this is an error-based shutdown
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            $this->log('error', 'AMQP Service: Fatal error occurred, closing connections', [
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }

        if ($this->connection !== null || !empty($this->channels)) {
            $this->log('info', 'AMQP Service: Application shutting down, closing connections');
            $this->shouldShutdown = true;
            $this->closeConnections();
        }
    }

    /**
     * Log message with configured channel
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        $channel = $this->config['logging']['channel'] ?? null;

        if ($channel) {
            Log::channel($channel)->{$level}("[AMQP] {$message}", $context);
        } else {
            Log::{$level}("[AMQP] {$message}", $context);
        }
    }
}
