<?php

namespace Rev\Amqp;

use Illuminate\Support\Facades\Facade;
use Rev\Amqp\Testing\FakeAmqp;

/**
 * @method static string publish(mixed $payload, string $exchange, string $routingKey = '', array $messageProperties = [], array $publishOptions = [])
 * @method static void consume(string $queue, callable $callback, array $options = [])
 * @method static array getStats()
 * @method static void closeConnections()
 * @method static FakeAmqp fake()
 * @method static void assertPublished(string $exchange, ?string $routingKey = null, ?\Closure $callback = null)
 * @method static void assertPublishedTo(string $queue, ?\Closure $callback = null)
 * @method static void assertNothingPublished()
 * @method static void assertPublishedCount(int $count)
 *
 * @see \Rev\Amqp\AmqpService
 */
class Amqp extends Facade
{
    public static function fake(): FakeAmqp
    {
        static::swap($fake = new FakeAmqp);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'amqp';
    }
}
