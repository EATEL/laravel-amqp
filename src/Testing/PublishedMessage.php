<?php

namespace Rev\Amqp\Testing;

readonly class PublishedMessage
{
    public function __construct(
        public mixed $payload,
        public string $exchange,
        public string $routingKey,
        public array $properties,
        public bool $isQueuePublish = false,
    ) {}
}
