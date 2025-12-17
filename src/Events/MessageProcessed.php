<?php

namespace Rev\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

class MessageProcessed
{
    public function __construct(
        public mixed $payload,
        public AMQPMessage $message,
        public string $queue,
        public mixed $result = null,
    ) {}
}
