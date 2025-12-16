<?php

namespace Rev\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

class MessageReceived
{
    public function __construct(
        public array $payload,
        public AMQPMessage $message,
        public string $queue,
    ) {}
}
