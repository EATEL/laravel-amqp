<?php

namespace Rev\Amqp\Events;

class MessagePublished
{
    public function __construct(
        public mixed $payload,
        public string $exchange,
        public string $routingKey,
        public array $messageProperties = [],
        public array $publishOptions = [],
    ) {}
}
