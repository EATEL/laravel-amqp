<?php

namespace Rev\Amqp\Console;

use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use Rev\Amqp\Contracts\Amqp;
use Rev\Amqp\AmqpConsumerServiceProvider;

class ConsumeCommand extends Command
{
    protected $signature = 'amqp:consume
                            {queue : The name of the queue to consume from}
                            {--prefetch=1 : Number of messages to prefetch}
                            {--timeout=0 : Timeout in seconds (0 = wait forever)}
                            {--on-error=requeue : Error handling strategy (requeue, reject)}
                            {--debug : Force debug mode (print messages instead of calling listeners)}';

    protected $description = 'Consume messages from a RabbitMQ queue';

    public function handle(Amqp $amqp): int
    {
        $queue = $this->argument('queue');
        $options = [
            'prefetch_count' => (int) $this->option('prefetch'),
            'timeout' => (int) $this->option('timeout'),
            'on_error' => $this->option('on-error'),
        ];

        $listeners = $this->getListenersForQueue($queue);
        $debugMode = $this->option('debug') || empty($listeners);

        if (!$debugMode) {
            $this->info("Starting consumer for queue: {$queue}");
            $this->info('Listeners: ' . count($listeners));
            foreach ($listeners as $listener) {
                $this->line("  - {$listener}");
            }
        } else {
            $this->info("Starting consumer for queue: {$queue} (debug mode)");
        }

        $this->info('Press Ctrl+C to stop');

        try {
            if ($debugMode) {
                $this->consumeDebugMode($amqp, $queue, $options);
            } else {
                $this->consumeWithListeners($amqp, $queue, $listeners, $options);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Consumer error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $amqp->disconnect();
        }
    }

    protected function getListenersForQueue(string $queue): array
    {
        $listeners = [];

        $providers = app()->getProviders(AmqpConsumerServiceProvider::class);

        foreach ($providers as $provider) {
            $providerListeners = $provider->getListeners($queue);
            $listeners = array_merge($listeners, $providerListeners);
        }

        return $listeners;
    }

    protected function consumeDebugMode(Amqp $amqp, string $queue, array $options): void
    {
        $amqp->consume($queue, function (array $payload, AMQPMessage $message) {
            $this->line('[' . now()->toDateTimeString() . '] Received message');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return true;
        }, $options);
    }

    protected function consumeWithListeners(Amqp $amqp, string $queue, array $listeners, array $options): void
    {
        $amqp->consume($queue, function (array $payload, AMQPMessage $message) use ($listeners) {
            foreach ($listeners as $listener) {
                $this->callListener($listener, $payload, $message);
            }

            return true;
        }, $options);
    }

    protected function callListener(string $listener, array $payload, AMQPMessage $message): void
    {
        [$class, $method] = $this->parseListener($listener);

        $instance = app($class);
        $instance->{$method}($payload, $message);
    }

    protected function parseListener(string $listener): array
    {
        if (str_contains($listener, '@')) {
            return explode('@', $listener, 2);
        }

        return [$listener, 'handle'];
    }
}
