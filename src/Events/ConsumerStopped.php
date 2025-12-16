<?php

namespace Rev\Amqp\Events;

class ConsumerStopped
{
    public function __construct(
        public string $queue,
        public ?string $reason = null,
    ) {}
}
