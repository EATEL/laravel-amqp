<?php

namespace Rev\Amqp\Contracts;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;

interface Amqp
{
    /**
     * Publish a message to an exchange.
     *
     * @param mixed $payload The payload to publish (will be JSON encoded)
     * @param string $exchange The exchange name
     * @param string $routingKey The routing key
     * @param array $properties Additional message properties
     */
    public function publish(
        mixed $payload,
        string $exchange,
        string $routingKey = '',
        array $properties = [],
    ): void;

    /**
     * Publish a message directly to a queue (using default exchange).
     *
     * @param mixed $payload The payload to publish (will be JSON encoded)
     * @param string $queue The queue name
     * @param array $properties Additional message properties
     */
    public function publishToQueue(
        mixed $payload,
        string $queue,
        array $properties = [],
    ): void;

    /**
     * Consume messages from a queue.
     *
     * The callback receives (array $payload, AMQPMessage $message).
     * Return true to ack, false to stop consuming, throw to nack.
     *
     * @param string $queue The queue name to consume from
     * @param Closure $callback The callback to process messages
     * @param array $options Consumer options (prefetch_count, timeout, on_error, consumer_tag)
     */
    public function consume(
        string $queue,
        Closure $callback,
        array $options = [],
    ): void;

    /**
     * Publish a message and wait for a response (RPC pattern).
     *
     * @param mixed $payload The request payload
     * @param string $exchange The exchange name
     * @param string $routingKey The routing key
     * @param int $timeout Timeout in seconds
     * @return mixed The response payload
     */
    public function rpc(
        mixed $payload,
        string $exchange,
        string $routingKey,
        int $timeout = 30,
    ): mixed;

    /**
     * Reply to an RPC request.
     *
     * @param AMQPMessage $request The original request message
     * @param mixed $response The response payload
     */
    public function replyTo(AMQPMessage $request, mixed $response): void;

    /**
     * Close all connections gracefully.
     */
    public function disconnect(): void;
}

