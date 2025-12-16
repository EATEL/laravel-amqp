<?php

namespace Rev\Amqp\Contracts;

interface Amqp
{
    /**
     * Publish a message to an exchange
     *
     * @param mixed $payload The payload to publish (will be JSON encoded)
     * @param string $exchange The exchange name
     * @param string $routingKey The routing key
     * @param array $messageProperties Additional message properties
     * @param array $publishOptions Publish options (mandatory, etc.)
     * @return string The message id.  If a `message_id` is in the messageProperties, it will be used otherwise one will be generated 
     */
    public function publish(
        mixed $payload,
        string $exchange,
        string $routingKey = '',
        array $messageProperties = [],
        array $publishOptions = [],
    ): string;

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
    ): void;

    /**
     * Get connection statistics
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * Close all connections
     *
     * @return void
     */
    public function closeConnections(): void;
}