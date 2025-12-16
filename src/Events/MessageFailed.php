<?php

namespace Rev\Amqp\Events;

use PhpAmqpLib\Message\AMQPMessage;

class MessageFailed
{
    public function __construct(
        public array $payload,
        public AMQPMessage $message,
        public string $queue,
        public \Throwable $exception,
    ) {}
}
