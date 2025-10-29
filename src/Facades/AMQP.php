<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * AMQP Facade for easy message publishing and consumption
 *
 * @method static void publish(mixed $payload, string $exchange, string $routingKey = '', array $properties = [], string $connectionName = 'default')
 * @method static void publishToQueue(mixed $payload, string $queue, array $properties = [], string $connectionName = 'default')
 * @method static void consume(string $queue, callable $callback, array $options = [], string $connectionName = 'default')

 * @method static array getStats()
 * @method static void closeConnections()
 *
 * @see \App\Services\AmqpService
 */
class AMQP extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \App\Services\AmqpService::class;
    }
}
