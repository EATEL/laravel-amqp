<?php

namespace Rev\Amqp\Facades;

use Illuminate\Support\Facades\Facade;
use Rev\Amqp\Contracts\Amqp as AmqpContract;
use Rev\Amqp\Testing\FakeAmqp;

/**
 * @method static void publish(mixed $payload, string $exchange, string $routingKey = '', array $properties = [])
 * @method static void publishToQueue(mixed $payload, string $queue, array $properties = [])
 * @method static void consume(string $queue, \Closure $callback, array $options = [])
 * @method static mixed rpc(mixed $payload, string $exchange, string $routingKey, int $timeout = 30)
 * @method static void replyTo(\PhpAmqpLib\Message\AMQPMessage $request, mixed $response)
 * @method static void disconnect()
 * @method static void assertPublished(string $exchange, ?string $routingKey = null, ?\Closure $callback = null)
 * @method static void assertPublishedTo(string $queue, ?\Closure $callback = null)
 * @method static void assertNothingPublished()
 * @method static void assertPublishedCount(int $count)
 * @method static FakeAmqp fakeRpcResponse(string $exchange, string $routingKey, mixed $response)
 * @method static array getPublished()
 *
 * @see \Rev\Amqp\Amqp
 * @see \Rev\Amqp\Testing\FakeAmqp
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
        return AmqpContract::class;
    }
}
