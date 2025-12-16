<?php

namespace Rev\Amqp\Events;

class ConsumerStarted
{
    public function __construct(
        public string $queue,
        public array $options = [],
    ) {}
}
