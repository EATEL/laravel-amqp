<?php

namespace Rev\Amqp;

use Closure;
use Illuminate\Support\Facades\Log;
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

class Amqp implements AmqpContract
{
    /**
     * Cache for connection instances
     */
    private static array $connections = [];

    /**
     * Cache for channel instances
     */
    private static array $channels = [];

    /**
     * Connection configurations cache
     */
    private static array $configs = [];

    /**
     * Retry attempts for each connection
     */
    private static array $retryAttempts = [];

    /**
     * Track if signal handlers are registered
     */
    private static bool $signalHandlersRegistered = false;

    /**
     * Flag to indicate if the service should shutdown
     */
    private static bool $shouldShutdown = false;

    /**
     * Default retry configuration
     */
    private static array $retryConfig = [
        'max_attempts' => 5,
        'initial_delay' => 1,    // seconds
        'max_delay' => 30,       // seconds
        'backoff_multiplier' => 2,
        'jitter' => true,        // add randomness to prevent thundering herd
    ];

    /**
     * Exceptions that should trigger a reconnection attempt
     */
    private static array $reconnectionExceptions = [
        AMQPConnectionClosedException::class,
        AMQPHeartbeatMissedException::class,
        AMQPIOException::class,
        AMQPSocketException::class,
        AMQPTimeoutException::class,
        AMQPRuntimeException::class,
        \ErrorException::class,
    ];

    /**
     * Default message properties for publishing
     */
    private static array $defaultMessageProperties = [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    ];

    private static array $defaultPublishOptions = [
        'mandatory' => false,
    ];

    /**
     * Default RPC configuration
     */
    private static array $defaultRpcOptions = [
        'timeout' => 30,  // seconds to wait for response
        'mandatory' => true,  // ensure message is routed to a queue
    ];

    /**
     * Initialize the service
     */
    private static function init(): void
    {
        if (! self::$signalHandlersRegistered) {
            self::setupCleanupHandlers();
            self::loadRetryConfig();
            self::$signalHandlersRegistered = true;
        }
    }

    /**
     * Publish a message to an exchange
     *
     * @param  mixed  $payload  The payload to publish (will be JSON encoded)
     * @param  string  $exchange  The exchange name
     * @param  string  $routingKey  The routing key
     * @param  array  $messageProperties  Additional message properties
     * @param  array  $publishProperties  Additional message properties
     * @param  string  $connectionName  The connection name to use
     */
    public static function publish(
        $payload,
        string $exchange,
        string $routingKey = '',
        array $messageProperties = [],
        array $publishOptions = [],
        string $connectionName = 'default'
    ): void {
        self::init();

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $messageProperties = array_merge(self::$defaultMessageProperties, $messageProperties);
        $publishOptions = array_merge(self::$defaultPublishOptions, $publishOptions);

        $message = new AMQPMessage($json, $messageProperties);

        self::execute(function ($channel) use ($message, $exchange, $routingKey, $publishOptions) {
            $channel->basic_publish($message, $exchange, $routingKey, $publishOptions['mandatory']);
            Log::debug("Published message to exchange '$exchange' with routing key '$routingKey'");
        }, $connectionName);
    }

    /**
     * Consume messages from a queue
     *
     * @param  string  $queue  The queue name to consume from
     * @param  callable  $callback  The callback function to handle messages
     * @param  array  $options  Consumption options
     * @param  string  $connectionName  The connection name to use
     */
    public static function consume(
        string $queue,
        Closure $callback,
        array $options = [],
        string $connectionName = 'default'
    ): void {
        self::init();

        $defaultOptions = [
            'consumer_tag' => '',
            'no_local' => false,
            'no_ack' => false,
            'exclusive' => false,
            'nowait' => false,
            'heartbeat_check' => 30,
        ];

        $options = array_merge($defaultOptions, $options);

        self::executeConsumer(function (AMQPChannel $channel) use ($queue, $callback, $options) {
            // Wrap the user callback to handle JSON decoding and return value
            $wrappedCallback = function (AMQPMessage $message) use ($callback, $options, $queue, $channel) {
                try {
                    // Check for shutdown signal before processing
                    if (self::$shouldShutdown) {
                        Log::info('Shutdown signal received, cancelling consumer');
                        $channel->basic_cancel($message->getConsumerTag());

                        return;
                    }

                    // Get message body
                    $body = $message->getBody();
                    // Check content type and parse accordingly
                    $contentType = $message->get('content_type');
                    if (true || $contentType === 'application/json') {
                        try {
                            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            Log::error('Failed to decode message JSON', [
                                'error' => $e->getMessage(),
                                'body' => $body,
                                'content_type' => $contentType,
                            ]);
                            // Reject message - it will be discarded or sent to DLQ
                            $message->nack(requeue: false, multiple: false);

                            return;
                        }
                    } else {
                        // Return raw body string for non-JSON content
                        $payload = $body;
                    }

                    // Call user callback with payload and message
                    $result = $callback($payload, $message);

                    // If callback returns false, stop consuming
                    if ($result === false) {
                        // Cancel the consumer to stop the loop
                        $channel->basic_cancel($message->getConsumerTag());
                    }

                    // Auto-acknowledge message unless no_ack is true
                    if (! $options['no_ack']) {
                        $message->ack();
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing message', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'queue' => $queue,
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

            Log::info("Started consuming from queue '$queue'");

            // Keep consuming until cancelled or shutdown
            while ($channel->is_consuming() && ! self::$shouldShutdown) {
                // Use a timeout on wait() to allow periodic shutdown checks
                try {
                    $channel->wait(allowed_methods: null, non_blocking: false, timeout: 1);
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // Timeout is expected, continue the loop
                    continue;
                }
            }

            Log::info("Stopped consuming from queue '$queue'");
        }, $connectionName, 'consumer', $options['heartbeat_check']);
    }

    /**
     * Get connection statistics
     */
    public static function getStats(): array
    {
        $stats = [];

        foreach (self::$connections as $name => $connection) {
            $stats[$name] = [
                'connected' => self::isConnectionHealthy($name),
                'retry_attempts' => self::$retryAttempts[$name] ?? 0,
            ];
        }

        return $stats;
    }

    public function disconnect(): void
    {
        self::closeConnections();
    }

    /**
     * Close all connections
     */
    public static function closeConnections(): void
    {
        Log::info('Closing AMQP connections', ['count' => count(self::$connections)]);

        // Close all channels first
        foreach (array_keys(self::$channels) as $channelKey) {
            [$connectionName, $channelId] = explode(':', $channelKey, 2);
            self::closeChannel($connectionName, $channelId);
        }

        // Then close all connections
        foreach (self::$connections as $name => $connection) {
            self::closeConnection($name);
        }

        self::$connections = [];
        self::$channels = [];
        self::$retryAttempts = [];
    }

    // ===== PRIVATE METHODS (Internal Connection Management) =====

    /**
     * Generate a unique correlation ID for RPC calls
     */
    private static function generateCorrelationId(): string
    {
        return uniqid('rpc_', true).'_'.mt_rand(1000, 9999);
    }

    /**
     * Load retry configuration from config
     */
    private static function loadRetryConfig(): void
    {
        $configRetry = config('amqp.retry', []);
        self::$retryConfig = array_merge(self::$retryConfig, $configRetry);
    }

    /**
     * Get or create an AMQP connection with automatic reconnection
     */
    private static function getConnection(string $name = 'default'): AbstractConnection
    {
        // Return existing healthy connection
        if (isset(self::$connections[$name]) && self::isConnectionHealthy($name)) {
            return self::$connections[$name];
        }

        // Connection doesn't exist or is unhealthy - create/recreate it
        return self::createConnectionWithRetry($name);
    }

    /**
     * Get or create an AMQP channel with automatic reconnection
     */
    private static function getChannel(string $connectionName = 'default', string $channelId = 'default'): \PhpAmqpLib\Channel\AMQPChannel
    {
        $channelKey = "$connectionName:$channelId";

        // Return existing healthy channel
        if (isset(self::$channels[$channelKey]) && self::isChannelHealthyInternal($channelKey)) {
            return self::$channels[$channelKey];
        }

        // Channel doesn't exist or is unhealthy - create/recreate it
        $connection = self::getConnection($connectionName);
        $channel = $connection->channel();

        self::$channels[$channelKey] = $channel;
        Log::debug("Created new AMQP channel: $channelKey");

        return $channel;
    }

    /**
     * Check if channel is healthy (internal method)
     */
    private static function isChannelHealthyInternal(string $channelKey): bool
    {
        if (! isset(self::$channels[$channelKey])) {
            return false;
        }

        try {
            $channel = self::$channels[$channelKey];

            // Check if channel is still open
            if (! $channel->is_open()) {
                Log::debug("Channel $channelKey is not open");

                return false;
            }

            // Check if the underlying connection is still healthy
            $connection = $channel->getConnection();
            if (! $connection->isConnected()) {
                Log::debug("Channel $channelKey connection is not connected");

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::debug("Channel $channelKey health check failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Check if connection is healthy
     */
    private static function isConnectionHealthy(string $name): bool
    {
        if (! isset(self::$connections[$name])) {
            return false;
        }

        try {
            $connection = self::$connections[$name];

            return $connection->isConnected();
        } catch (\Exception $e) {
            Log::debug("AMQP connection '$name' health check failed", [
                'exception' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Create connection with retry logic
     */
    private static function createConnectionWithRetry(string $name): AbstractConnection
    {
        $attempts = self::$retryAttempts[$name] ?? 0;
        $maxAttempts = self::$retryConfig['max_attempts'];

        while ($attempts < $maxAttempts && ! self::$shouldShutdown) {
            try {
                $connection = self::createConnection($name);

                // Success - reset retry counter and cache connection
                self::$retryAttempts[$name] = 0;
                self::$connections[$name] = $connection;

                if ($attempts > 0) {
                    Log::info("AMQP connection '$name' restored after $attempts attempts");
                }

                return $connection;
            } catch (\Exception $e) {
                $attempts++;
                self::$retryAttempts[$name] = $attempts;

                // Remove failed connection from cache
                if (isset(self::$connections[$name])) {
                    unset(self::$connections[$name]);
                }

                if ($attempts >= $maxAttempts || self::$shouldShutdown) {
                    if (self::$shouldShutdown) {
                        throw new \RuntimeException('AMQP connection creation aborted due to shutdown signal');
                    }

                    Log::error("AMQP connection '$name' failed after $maxAttempts attempts", [
                        'exception' => $e->getMessage(),
                        'attempts' => $attempts,
                    ]);
                    throw $e;
                }

                $delay = self::calculateBackoffDelay($attempts);
                Log::warning("AMQP connection '$name' failed, retrying in {$delay}s (attempt $attempts/$maxAttempts)", [
                    'exception' => $e->getMessage(),
                ]);

                // Sleep with shutdown check
                for ($i = 0; $i < $delay && ! self::$shouldShutdown; $i++) {
                    sleep(1);
                }
            }
        }

        throw new \RuntimeException("Failed to establish AMQP connection '$name' - shutdown requested");
    }

    /**
     * Calculate backoff delay with jitter
     */
    private static function calculateBackoffDelay(int $attempt): int
    {
        $delay = min(
            self::$retryConfig['initial_delay'] * pow(self::$retryConfig['backoff_multiplier'], $attempt - 1),
            self::$retryConfig['max_delay']
        );

        // Add jitter to prevent thundering herd problem
        if (self::$retryConfig['jitter']) {
            $jitter = $delay * 0.1; // 10% jitter
            $delay += mt_rand(-$jitter * 100, $jitter * 100) / 100;
        }

        return max(1, (int) $delay);
    }

    /**
     * Create a new AMQP connection
     */
    private static function createConnection(string $name): AbstractConnection
    {
        // Cache config to avoid repeated config() calls
        if (! isset(self::$configs[$name])) {
            self::$configs[$name] = self::buildConnectionConfig($name);
        }

        return AMQPConnectionFactory::create(self::$configs[$name]);
    }

    /**
     * Build connection configuration
     */
    private static function buildConnectionConfig(string $name): AMQPConnectionConfig
    {
        $config = new AMQPConnectionConfig;
        $config->setHost(config("amqp.connections.$name.host"));
        $config->setPort(config("amqp.connections.$name.port"));
        $config->setUser(config("amqp.connections.$name.user"));
        $config->setPassword(config("amqp.connections.$name.password"));
        $config->setVhost(config("amqp.connections.$name.vhost"));
        $config->setIsSecure(self::shouldUseSecureConnection($name));

        // Set connection and read timeouts for better failure detection
        $config->setConnectionTimeout(config("amqp.connections.$name.connection_timeout", 10));
        $config->setReadTimeout(config("amqp.connections.$name.read_timeout", 3));
        $config->setWriteTimeout(config("amqp.connections.$name.write_timeout", 3));
        $config->setHeartbeat(config("amqp.connections.$name.heartbeat", 10));

        return $config;
    }

    /**
     * Determine if connection should be secure based on host
     */
    private static function shouldUseSecureConnection(string $name): bool
    {
        $host = config("amqp.connections.$name.host");

        return ! in_array($host, ['localhost', '127.0.0.1']);
    }

    /**
     * Execute a callback with automatic reconnection using a channel
     */
    private static function execute(callable $callback, string $connectionName = 'default', string $channelId = 'default', ...$args)
    {
        $maxAttempts = self::$retryConfig['max_attempts'];
        $attempts = 0;

        while ($attempts < $maxAttempts && ! self::$shouldShutdown) {
            try {
                $channel = self::getChannel($connectionName, $channelId);

                return $callback($channel, ...$args);
            } catch (\Exception $e) {
                $attempts++;

                // Check if this is a connection-related exception
                if (! self::shouldRetryException($e) || $attempts >= $maxAttempts || self::$shouldShutdown) {

                    // Check shutdown before throwing the final exception
                    if (self::$shouldShutdown) {
                        throw new \RuntimeException('AMQP operation aborted due to shutdown signal');
                    }

                    throw $e;
                }

                Log::warning("AMQP operation failed, retrying (attempt $attempts/$maxAttempts)", [
                    'connection' => $connectionName,
                    'channel' => $channelId,
                    'exception' => $e->getMessage(),
                ]);

                // Mark connection and channel as failed so they get recreated
                self::closeChannel($connectionName, $channelId);
                if (isset(self::$connections[$connectionName])) {
                    unset(self::$connections[$connectionName]);
                }

                $delay = self::calculateBackoffDelay($attempts);

                // Sleep with shutdown check
                for ($i = 0; $i < $delay && ! self::$shouldShutdown; $i++) {
                    sleep(1);
                }
            }
        }

        // Check shutdown before throwing the final exception
        if (self::$shouldShutdown) {
            throw new \RuntimeException('AMQP operation aborted due to shutdown signal');
        }
        throw new \RuntimeException("AMQP operation failed after $maxAttempts attempts");
    }

    /**
     * Execute a consumer callback with automatic reconnection and heartbeat monitoring
     */
    private static function executeConsumer(callable $callback, string $connectionName = 'default', string $channelId = 'consumer', int $heartbeatCheck = 30, ...$args)
    {
        $lastHeartbeatCheck = time();

        while (! self::$shouldShutdown) {
            try {
                $channel = self::getChannel($connectionName, $channelId);

                // Check connection health periodically during consumption
                if (time() - $lastHeartbeatCheck >= $heartbeatCheck) {
                    if (! self::validateConnection($connectionName, true) || ! self::isChannelHealthyInternal("$connectionName:$channelId")) {
                        Log::warning("AMQP connection/channel '$connectionName:$channelId' failed health check during consumption, reconnecting");
                        self::closeChannel($connectionName, $channelId);
                        self::closeConnection($connectionName);

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
                if (self::$shouldShutdown) {
                    Log::info('AMQP consumer shutting down due to signal');
                    break;
                }

                Log::error('AMQP consumer error', [
                    'connection' => $connectionName,
                    'channel' => $channelId,
                    'exception' => $e->getMessage(),
                    'type' => get_class($e),
                ]);

                // Close the failed channel and connection
                self::closeChannel($connectionName, $channelId);
                self::closeConnection($connectionName);

                // Check if this is a retryable exception
                if (! self::shouldRetryException($e)) {
                    throw $e;
                }

                $delay = self::calculateBackoffDelay((self::$retryAttempts[$connectionName] ?? 0) + 1);
                Log::info("Consumer will retry in {$delay}s");

                // Sleep with shutdown check
                for ($i = 0; $i < $delay && ! self::$shouldShutdown; $i++) {
                    sleep(1);
                }
            }
        }

        Log::info('AMQP consumer loop exited');
    }

    /**
     * Check if exception should trigger a retry
     */
    private static function shouldRetryException(\Exception $e): bool
    {
        foreach (self::$reconnectionExceptions as $exceptionClass) {
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
            'transport endpoint is not connected',
        ];

        foreach ($networkErrors as $error) {
            if (strpos($message, $error) !== false) {
                Log::debug('Detected network error, will retry', [
                    'exception' => $e->getMessage(),
                    'type' => get_class($e),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Close a specific channel
     */
    private static function closeChannel(string $connectionName = 'default', string $channelId = 'default'): void
    {
        $channelKey = "$connectionName:$channelId";

        if (isset(self::$channels[$channelKey])) {
            try {
                if (self::$channels[$channelKey]->is_open()) {
                    self::$channels[$channelKey]->close();
                }
                unset(self::$channels[$channelKey]);
                Log::debug("Closed AMQP channel: $channelKey");
            } catch (\Exception $e) {
                Log::error("Error closing AMQP channel $channelKey", ['exception' => $e->getMessage()]);
                // Remove from cache anyway
                unset(self::$channels[$channelKey]);
            }
        }
    }

    /**
     * Close a specific connection
     */
    private static function closeConnection(string $name = 'default'): void
    {
        // First close all channels for this connection
        foreach (array_keys(self::$channels) as $channelKey) {
            if (str_starts_with($channelKey, "$name:")) {
                [$connectionName, $channelId] = explode(':', $channelKey, 2);
                self::closeChannel($connectionName, $channelId);
            }
        }

        if (isset(self::$connections[$name])) {
            try {
                if (self::$connections[$name]->isConnected()) {
                    self::$connections[$name]->close();
                }
                unset(self::$connections[$name]);
                Log::debug("Closed AMQP connection: $name");
            } catch (\Exception $e) {
                Log::error("Error closing AMQP connection $name", ['exception' => $e->getMessage()]);
                // Remove from cache anyway
                unset(self::$connections[$name]);
            }
        }

        // Reset retry counter
        unset(self::$retryAttempts[$name]);
    }

    /**
     * Validate connection with optional deep health check
     */
    private static function validateConnection(string $name = 'default', bool $deepCheck = false): bool
    {
        if (! isset(self::$connections[$name])) {
            return false;
        }

        try {
            $connection = self::$connections[$name];

            // Basic check - only checks internal connection state
            if (! $connection->isConnected()) {
                return false;
            }

            // Deep check - actually tests the network connection
            if ($deepCheck) {
                return self::performDeepConnectionCheck($connection, $name);
            }

            return true;
        } catch (\Exception $e) {
            Log::debug('AMQP connection validation failed', [
                'connection' => $name,
                'exception' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            return false;
        }
    }

    /**
     * Perform a deep connection check that actually tests network connectivity
     */
    private static function performDeepConnectionCheck(AbstractConnection $connection, string $name): bool
    {
        try {
            // Test the connection by sending a heartbeat frame
            // This will fail immediately if there are SSL/network issues
            $connection->checkHeartBeat();

            return true;
        } catch (\Exception $e) {
            Log::debug('AMQP deep connection check failed', [
                'connection' => $name,
                'exception' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            // Mark connection as failed so it gets recreated
            if (isset(self::$connections[$name])) {
                unset(self::$connections[$name]);
            }

            return false;
        }
    }

    /**
     * Set up all cleanup handlers (signals, shutdown, destructor)
     */
    private static function setupCleanupHandlers(): void
    {
        // Register shutdown function (works for most termination scenarios)
        register_shutdown_function([self::class, 'handleShutdown']);

        // Register signal handlers if PCNTL extension is available
        if (extension_loaded('pcntl')) {
            self::registerSignalHandlers();
        }
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private static function registerSignalHandlers(): void
    {
        // Handle SIGTERM (kill command)
        pcntl_signal(SIGTERM, [self::class, 'handleSignal']);

        // Handle SIGINT (Ctrl+C)
        pcntl_signal(SIGINT, [self::class, 'handleSignal']);

        // Handle SIGHUP (terminal closed)
        pcntl_signal(SIGHUP, [self::class, 'handleSignal']);

        // Enable async signal handling (PHP 7.1+)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Handle Unix signals
     */
    public static function handleSignal(int $signal): void
    {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
        ];

        $signalName = $signalNames[$signal] ?? "Signal $signal";
        Log::info("AMQP Service received $signalName, initiating graceful shutdown");

        // Set shutdown flag to break out of consumer loops
        self::$shouldShutdown = true;

        // Close connections
        self::closeConnections();
    }

    /**
     * Handle shutdown function (fallback for various termination scenarios)
     */
    public static function handleShutdown(): void
    {
        // Check if this is an error-based shutdown
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            Log::error('AMQP Service: Fatal error occurred, closing connections', [
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        }

        if (! empty(self::$connections)) {
            Log::info('AMQP Service: Application shutting down, closing connections');
            self::$shouldShutdown = true;
            self::closeConnections();
        }
    }
}
