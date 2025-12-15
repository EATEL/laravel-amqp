<?php

namespace Rev\Amqp;

use Illuminate\Support\ServiceProvider;

abstract class AmqpConsumerServiceProvider extends ServiceProvider
{
    protected array $listen = [
        //
    ];

    public function getListeners(string $queue): array
    {
        return $this->listen[$queue] ?? [];
    }

    public function getQueues(): array
    {
        return array_keys($this->listen);
    }
}
