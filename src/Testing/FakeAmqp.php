<?php

namespace Rev\Amqp\Testing;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Assert;
use Rev\Amqp\Contracts\Amqp as AmqpContract;

class FakeAmqp implements AmqpContract
{
    protected array $published = [];

    protected array $rpcResponses = [];

    public function publish(
        mixed $payload,
        string $exchange,
        string $routingKey = '',
        array $properties = [],
    ): void {
        $this->published[] = new PublishedMessage(
            payload: $payload,
            exchange: $exchange,
            routingKey: $routingKey,
            properties: $properties,
            isQueuePublish: false,
        );
    }

    public function consume(
        string $queue,
        Closure $callback,
        array $options = [],
    ): void {
    }


    public function fakeRpcResponse(string $exchange, string $routingKey, mixed $response): self
    {
        $this->rpcResponses["{$exchange}:{$routingKey}"] = $response;

        return $this;
    }

    public function assertPublished(
        string $exchange,
        ?string $routingKey = null,
        ?Closure $callback = null,
    ): void {
        $matching = $this->getMatchingPublished($exchange, $routingKey, false);

        Assert::assertNotEmpty(
            $matching,
            "No messages were published to exchange [{$exchange}]".
            ($routingKey ? " with routing key [{$routingKey}]" : '').'.'
        );

        if ($callback) {
            $found = false;
            foreach ($matching as $message) {
                if ($callback($message->payload, $message)) {
                    $found = true;
                    break;
                }
            }

            Assert::assertTrue(
                $found,
                "No published message matched the callback criteria for exchange [{$exchange}]."
            );
        }
    }

    public function assertPublishedTo(string $queue, ?Closure $callback = null): void
    {
        $matching = $this->getMatchingPublished('', $queue, true);

        Assert::assertNotEmpty(
            $matching,
            "No messages were published to queue [{$queue}]."
        );

        if ($callback) {
            $found = false;
            foreach ($matching as $message) {
                if ($callback($message->payload, $message)) {
                    $found = true;
                    break;
                }
            }

            Assert::assertTrue(
                $found,
                "No published message matched the callback criteria for queue [{$queue}]."
            );
        }
    }

    public function assertNothingPublished(): void
    {
        Assert::assertEmpty(
            $this->published,
            'Messages were published unexpectedly: '.json_encode(
                array_map(fn ($m) => [
                    'exchange' => $m->exchange,
                    'routingKey' => $m->routingKey,
                    'payload' => $m->payload,
                ], $this->published)
            )
        );
    }

    public function assertPublishedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->published,
            "Expected {$count} messages to be published, but ".count($this->published).' were published.'
        );
    }

    public function getPublished(): array
    {
        return $this->published;
    }

    protected function getMatchingPublished(string $exchange, ?string $routingKey, bool $isQueuePublish): array
    {
        return array_filter($this->published, function (PublishedMessage $message) use ($exchange, $routingKey, $isQueuePublish) {
            if ($message->isQueuePublish !== $isQueuePublish) {
                return false;
            }

            if ($message->exchange !== $exchange) {
                return false;
            }

            if ($routingKey !== null && $message->routingKey !== $routingKey) {
                return false;
            }

            return true;
        });
    }
}
