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
use Rev\Amqp\Exceptions\AmqpException;
use Rev\Amqp\Support\ConnectionUrlParser;

class Amqp implements AmqpContract
{
    protected ?AbstractConnection $connection = null;

    protected ?AMQPChannel $channel = null;

    protected bool $shouldShutdown = false;

    protected bool $signalHandlersRegistered = false;

    protected array $reconnectionExceptions = [
        AMQPConnectionClosedException::class,
        AMQPHeartbeatMissedException::class,
        AMQPIOException::class,
        AMQPSocketException::class,
        AMQPTimeoutException::class,
        AMQPRuntimeException::class,
    ];

    public function __construct(
        protected readonly array $config,
    ) {}

    public function publish(
        mixed $payload,
        string $exchange,
        string $routingKey = '',
        array $properties = [],
    ): void {
        $message = $this->createMessage($payload, $properties);

        $this->executeWithRetry(function (AMQPChannel $channel) use ($message, $exchange, $routingKey) {
            $channel->basic_publish($message, $exchange, $routingKey);
            $this->log('debug', "Published message to exchange '{$exchange}' with routing key '{$routingKey}'");
        });
    }

    public function publishToQueue(
        mixed $payload,
        string $queue,
        array $properties = [],
    ): void {
        $this->publish($payload, '', $queue, $properties);
    }

    public function consume(
        string $queue,
        Closure $callback,
        array $options = [],
    ): void {
        $this->registerSignalHandlers();

        $options = array_merge([
            'prefetch_count' => $this->config['consume']['prefetch_count'] ?? 1,
            'timeout' => $this->config['consume']['timeout'] ?? 0,
            'on_error' => $this->config['consume']['on_error'] ?? 'requeue',
            'consumer_tag' => '',
        ], $options);

        $this->validateConnection();

        $this->executeConsumer(function (AMQPChannel $channel) use ($queue, $callback, $options) {
            $channel->basic_qos(0, $options['prefetch_count'], false);

            $wrappedCallback = function (AMQPMessage $message) use ($callback, $options, $queue, $channel) {
                if ($this->shouldShutdown) {
                    $this->log('info', 'Shutdown signal received, cancelling consumer');
                    $channel->basic_cancel($message->getConsumerTag());

                    return;
                }

                try {
                    $payload = $this->decodeMessageBody($message);

                    $this->log('debug', "Consuming message from queue '{$queue}'", [
                        'payload_size' => strlen($message->getBody()),
                    ]);

                    $result = $callback($payload, $message);

                    if ($result === false) {
                        $channel->basic_cancel($message->getConsumerTag());
                    }

                    $message->ack();
                } catch (\Throwable $e) {
                    $this->log('error', 'Error processing message', [
                        'error' => $e->getMessage(),
                        'queue' => $queue,
                    ]);

                    $this->handleConsumeError($message, $options['on_error']);
                }
            };

            $channel->basic_consume(
                queue: $queue,
                consumer_tag: $options['consumer_tag'],
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $wrappedCallback,
            );

            $this->log('info', "Started consuming from queue '{$queue}'");

            while ($channel->is_consuming() && ! $this->shouldShutdown) {
                try {
                    $channel->wait(null, false, $options['timeout'] ?: null);
                } catch (AMQPTimeoutException) {
                    continue;
                }
            }

            $this->log('info', "Stopped consuming from queue '{$queue}'");
        });
    }

    public function rpc(
        mixed $payload,
        string $exchange,
        string $routingKey,
        int $timeout = 30,
    ): mixed {
        $correlationId = $this->generateCorrelationId();
        $response = null;
        $responseReceived = false;
        $responseError = null;

        $properties = [
            'reply_to' => 'amq.rabbitmq.reply-to',
            'correlation_id' => $correlationId,
        ];

        $message = $this->createMessage($payload, $properties);

        return $this->executeWithRetry(function (AMQPChannel $channel) use (
            $message, $exchange, $routingKey, $correlationId, $timeout,
            &$response, &$responseReceived, &$responseError
        ) {
            $consumerTag = 'rpc_'.$correlationId;

            $channel->basic_consume(
                queue: 'amq.rabbitmq.reply-to',
                consumer_tag: $consumerTag,
                no_local: false,
                no_ack: true,
                exclusive: false,
                nowait: false,
                callback: function (AMQPMessage $replyMessage) use (
                    $correlationId, &$responseReceived, &$response, &$responseError, $channel, $consumerTag
                ) {
                    if ($replyMessage->get('correlation_id') !== $correlationId) {
                        return;
                    }

                    try {
                        $response = $this->decodeMessageBody($replyMessage);
                        $responseReceived = true;
                        $channel->basic_cancel($consumerTag);
                    } catch (\Throwable $e) {
                        $responseError = $e;
                        $responseReceived = true;
                        $channel->basic_cancel($consumerTag);
                    }
                },
            );

            $channel->basic_publish($message, $exchange, $routingKey);
            $this->log('debug', 'Published RPC request', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'correlation_id' => $correlationId,
            ]);

            $startTime = time();

            while (! $responseReceived && ! $this->shouldShutdown) {
                $elapsed = time() - $startTime;

                if ($elapsed >= $timeout) {
                    try {
                        $channel->basic_cancel($consumerTag);
                    } catch (\Throwable) {
                    }

                    throw new AmqpException("RPC call timed out after {$timeout} seconds");
                }

                try {
                    $channel->wait(null, false, min(1, $timeout - $elapsed));
                } catch (AMQPTimeoutException) {
                    continue;
                }
            }

            if ($this->shouldShutdown) {
                throw new AmqpException('RPC call aborted due to shutdown signal');
            }

            if ($responseError !== null) {
                throw $responseError;
            }

            return $response;
        });
    }

    public function replyTo(AMQPMessage $request, mixed $response): void
    {
        $replyTo = $request->get('reply_to');
        $correlationId = $request->get('correlation_id');

        if (empty($replyTo)) {
            throw new AmqpException('Request message does not have a reply_to property');
        }

        if (empty($correlationId)) {
            throw new AmqpException('Request message does not have a correlation_id property');
        }

        $properties = ['correlation_id' => $correlationId];
        $message = $this->createMessage($response, $properties);

        $this->executeWithRetry(function (AMQPChannel $channel) use ($message, $replyTo, $correlationId) {
            $channel->basic_publish($message, '', $replyTo);
            $this->log('debug', 'Sent RPC reply', [
                'reply_to' => $replyTo,
                'correlation_id' => $correlationId,
            ]);
        });
    }

    public function disconnect(): void
    {
        $this->log('info', 'Closing AMQP connections');

        if ($this->channel !== null) {
            try {
                if ($this->channel->is_open()) {
                    $this->channel->close();
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Error closing channel', ['error' => $e->getMessage()]);
            }
            $this->channel = null;
        }

        if ($this->connection !== null) {
            try {
                if ($this->connection->isConnected()) {
                    $this->connection->close();
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Error closing connection', ['error' => $e->getMessage()]);
            }
            $this->connection = null;
        }
    }

    protected function getConnection(): AbstractConnection
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return $this->connection;
        }

        $this->connection = $this->createConnection();

        return $this->connection;
    }

    protected function getChannel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            $connection = $this->channel->getConnection();
            if ($connection !== null && $connection->isConnected()) {
                return $this->channel;
            }
        }

        $this->channel = $this->getConnection()->channel();

        return $this->channel;
    }

    protected function validateConnection(): void
    {
        try {
            $channel = $this->getChannel();
            if (! $channel->is_open()) {
                throw new AmqpException('Failed to open channel. Please check your connection settings.');
            }
            $connection = $channel->getConnection();
            if ($connection === null || ! $connection->isConnected()) {
                throw new AmqpException('Failed to establish connection. Please verify your AMQP connection settings (host, port, credentials).');
            }
        } catch (\Throwable $e) {
            $this->resetConnection();
            if ($e instanceof AmqpException) {
                throw $e;
            }
            $host = $this->config['connection']['host'] ?? 'unknown';
            $port = $this->config['connection']['port'] ?? 'unknown';
            throw new AmqpException("Failed to connect to AMQP server at {$host}:{$port}. Error: ".$e->getMessage(), 0, $e);
        }
    }

    protected function createConnection(): AbstractConnection
    {
        $connConfig = $this->config['connection'];

        if (! empty($connConfig['url'])) {
            $parsedUrl = ConnectionUrlParser::parse($connConfig['url']);
            $connConfig = array_merge($connConfig, $parsedUrl);

            if (isset($parsedUrl['ssl'])) {
                $connConfig['ssl'] = array_merge(
                    $connConfig['ssl'] ?? [],
                    $parsedUrl['ssl']
                );
            }
        }

        $config = new AMQPConnectionConfig;
        $config->setHost($connConfig['host']);
        $config->setPort($connConfig['port']);
        $config->setUser($connConfig['user']);
        $config->setPassword($connConfig['password']);
        $config->setVhost($connConfig['vhost']);
        $config->setHeartbeat($connConfig['heartbeat'] ?? 60);
        $config->setConnectionTimeout($connConfig['connection_timeout'] ?? 10);
        $config->setReadTimeout($connConfig['read_timeout'] ?? 10);
        $config->setWriteTimeout($connConfig['write_timeout'] ?? 10);

        if ($connConfig['ssl']['enabled'] ?? false) {
            $config->setIsSecure(true);
            if (isset($connConfig['ssl']['cafile'])) {
                $config->setSslCaCert($connConfig['ssl']['cafile']);
            }
            $config->setSslVerify($connConfig['ssl']['verify_peer'] ?? true);
        } else {
            $config->setIsSecure(! in_array($connConfig['host'], ['localhost', '127.0.0.1']));
        }

        return AMQPConnectionFactory::create($config);
    }

    protected function createMessage(mixed $payload, array $properties = []): AMQPMessage
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $defaultProperties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        return new AMQPMessage($json, array_merge($defaultProperties, $properties));
    }

    protected function decodeMessageBody(AMQPMessage $message): mixed
    {
        $body = $message->getBody();
        $contentType = $message->get('content_type');

        if ($contentType === 'application/json') {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        return $body;
    }

    protected function executeWithRetry(Closure $callback): mixed
    {
        $retryConfig = $this->config['retry'];
        $maxAttempts = $retryConfig['max_attempts'];
        $attempts = 0;

        while ($attempts < $maxAttempts && ! $this->shouldShutdown) {
            try {
                $channel = $this->getChannel();

                return $callback($channel);
            } catch (\Throwable $e) {
                $attempts++;

                // @phpstan-ignore booleanOr.rightAlwaysFalse (signal handlers can modify shouldShutdown asynchronously)
                if (! $this->shouldRetry($e) || $attempts >= $maxAttempts || $this->shouldShutdown) {
                    // @phpstan-ignore if.alwaysFalse (signal handlers can modify shouldShutdown asynchronously)
                    if ($this->shouldShutdown) {
                        throw new AmqpException('AMQP operation aborted due to shutdown signal');
                    }
                    throw $e;
                }

                $this->log('warning', "AMQP operation failed, retrying (attempt {$attempts}/{$maxAttempts})", [
                    'error' => $e->getMessage(),
                ]);

                $this->resetConnection();

                $delay = $this->calculateBackoffDelay($attempts, $retryConfig);
                $this->sleepWithShutdownCheck($delay);
            }
        }

        throw new AmqpException("AMQP operation failed after {$maxAttempts} attempts");
    }

    protected function executeConsumer(Closure $callback): void
    {
        $retryConfig = $this->config['retry'];

        while (! $this->shouldShutdown) {
            try {
                $channel = $this->getChannel();
                $callback($channel);
                break;
            } catch (\Throwable $e) {
                // @phpstan-ignore if.alwaysFalse (signal handlers can modify shouldShutdown asynchronously)
                if ($this->shouldShutdown) {
                    $this->log('info', 'Consumer shutting down due to signal');
                    break;
                }

                $this->log('error', 'Consumer error', [
                    'error' => $e->getMessage(),
                ]);

                if (! $this->shouldRetry($e)) {
                    throw $e;
                }

                $this->resetConnection();

                $delay = $this->calculateBackoffDelay(1, $retryConfig);
                $this->log('info', "Consumer will retry in {$delay}s");
                $this->sleepWithShutdownCheck($delay);
            }
        }
    }

    protected function shouldRetry(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $nonRetryableErrors = [
            'access_refused',
            'authentication',
            'invalid credentials',
            'login failed',
            'access denied',
        ];

        foreach ($nonRetryableErrors as $error) {
            if (str_contains($message, $error)) {
                return false;
            }
        }

        foreach ($this->reconnectionExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        $networkErrors = [
            'broken pipe',
            'connection reset by peer',
            'ssl operation failed',
            'connection timed out',
            'network is unreachable',
            'connection refused',
        ];

        foreach ($networkErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }

        return false;
    }

    protected function calculateBackoffDelay(int $attempt, array $retryConfig): int
    {
        $multiplier = $retryConfig['multiplier'] ?? $retryConfig['backoff_multiplier'] ?? 2;

        $delay = min(
            $retryConfig['initial_delay'] * pow($multiplier, $attempt - 1),
            $retryConfig['max_delay']
        );

        $jitter = $delay * 0.1;
        $delay += mt_rand((int) (-$jitter * 100), (int) ($jitter * 100)) / 100;

        return max(1, (int) $delay);
    }

    protected function sleepWithShutdownCheck(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->shouldShutdown; $i++) {
            sleep(1);
        }
    }

    protected function resetConnection(): void
    {
        $this->channel = null;
        $this->connection = null;
    }

    protected function handleConsumeError(AMQPMessage $message, string $onError): void
    {
        match ($onError) {
            'reject' => $message->nack(requeue: false),
            default => $message->nack(requeue: true),
        };
    }

    protected function registerSignalHandlers(): void
    {
        if ($this->signalHandlersRegistered) {
            return;
        }

        if (! extension_loaded('pcntl')) {
            return;
        }

        $handler = function (int $signal) {
            $signalNames = [
                SIGTERM => 'SIGTERM',
                SIGINT => 'SIGINT',
                SIGHUP => 'SIGHUP',
            ];

            $this->log('info', 'Received '.($signalNames[$signal] ?? "signal {$signal}").', initiating graceful shutdown');
            $this->shouldShutdown = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $this->signalHandlersRegistered = true;
    }

    protected function generateCorrelationId(): string
    {
        return uniqid('rpc_', true).'_'.mt_rand(1000, 9999);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
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
