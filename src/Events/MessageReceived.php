<?php

namespace Rev\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

class MessageReceived
{
    public function __construct(
        public mixed $payload,
        public AMQPMessage $message,
        public string $queue,
    ) {}
}
