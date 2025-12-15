<?php

namespace Rev\Amqp\Contracts;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;

interface Amqp
{
    /**
     * Publish a message to an exchange.
     *
     * @param  mixed  $payload  The payload to publish (will be JSON encoded)
     * @param  string  $exchange  The exchange name
     * @param  string  $routingKey  The routing key
     * @param  array  $properties  Additional message properties
     */
    public static function publish(
        $payload,
        string $exchange,
        string $routingKey = '',
        array $messageProperties = [],
        array $publishOptions = [],
        string $connectionName = 'default'
    ): void;

    /**
     * Consume messages from a queue.
     *
     * The callback receives (array $payload, AMQPMessage $message).
     * Return true to ack, false to stop consuming, throw to nack.
     *
     * @param  string  $queue  The queue name to consume from
     * @param  Closure  $callback  The callback to process messages
     * @param  array  $options  Consumer options (prefetch_count, timeout, on_error, consumer_tag)
     */
    public static function consume(
        string $queue,
        Closure $callback,
        array $options = [],
        string $connectionName = 'default'
    ): void;

    /**
     * Close all connections gracefully.
     */
    public function disconnect(): void;
}
