<?php

namespace App\Console\Commands;

use App\Models\ReceivedAMQPMessage;
use App\Services\SalesforceMessageProcessorResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use PhpAmqpLib\Message\AMQPMessage;
use Rev\Amqp\Amqp;
use Str;

class ProcessSalesforceMessages extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'salesforce:process-messages
                            {--queue= : The AMQP queue name to consume from (defaults to config)}';

    /**
     * The console command description.
     */
    protected $description = 'Consume and process Salesforce order events from AMQP queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queueConfig = config('amqp.queues.salesforce');
        $queue = $this->option('queue') ?: $queueConfig['name'];
        $connection = $queueConfig['connection'];

        $this->info("Starting to consume Salesforce orders from queue: {$queue}");

        Log::info('Starting Salesforce order consumer', [
            'queue' => $queue,
            'connection' => $connection,
        ]);

        try {
            Amqp::consume(
                queue: $queue,
                callback: function (array $payload, AMQPMessage $message) use ($queue) {
                    if (!$message->has('message_id')) {
                        $eventId = Arr::get($payload, 'eventId');
                        if($eventId) {
                            $messageId = $eventId;
                            Log::warning('Message received without message_id header, used eventId ' . $messageId . ' from payload', [
                                'routing_key' => $message->getRoutingKey(),
                                'correlation_id' => $message->get('correlation_id'),
                            ]);
                        } else {
                            $messageId = Str::ulid();
                            Log::error('Message received without message_id header, created artifical id ' . $messageId, [
                                'routing_key' => $message->getRoutingKey(),
                                'correlation_id' => $message->get('correlation_id'),
                            ]);
                        }
                    } else {
                        $messageId = $message->get('message_id');
                    }
                    
                    $log = ReceivedAMQPMessage::firstOrCreate([
                        'message_id' => $messageId,
                    ], [
                        'correlation_id' => $message->get('correlation_id') ?? Str::ulid(),
                        'received_on_queue' => $queue,
                        'payload' => $payload,
                        'received_at' => now(),
                        'routing_key' => $message->getRoutingKey(),
                    ]);

                    if($log->status === 'processing') {
                        // weird state - how did we get this message twice on two different threads?
                        // or maybe status changed or it never got saved after processing?
                        Log::warning('Message is already processing', [
                            'message_id' => $log->message_id,
                            'started_at' => $log->received_at,
                        ]);
                        return;
                    }
                    $resolver = app(SalesforceMessageProcessorResolver::class);
                    $processor = $resolver->resolve(
                        $payload, 
                        $message->getRoutingKey(), 
                        $log->message_id,
                    );

                    if (!$processor) {
                        $log->status = 'no_processor_found';
                        $log->save();
                        return;
                    }

                    $processor->setLogger(function (string $level, string $message, array $context) use ($log) {
                        $logs = $log->processor_log;
                        $logs->push([
                            'timestamp' => now()->toIso8601String(),
                            'level' => $level,
                            'message' => $message,
                            'context' => $context,
                        ]);
                        $log->processor_log = $logs;
                    });

                    $log->processed_by_class = get_class($processor);
                    $log->status = 'processing';
                    $log->save();
                    try {
                        $start = microtime(true);
                        $processor->process();
                        $log->status = 'processed';
                    } catch (\Exception $e) {
                        $log->status = 'failed';
                        $log->error = [
                            'message' =>$e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ];
                        // TODO: Configure DLQ in RabbitMQ
                        throw $e;
                    } finally {
                        $log->elapsed = microtime(true) - $start;
                        $log->save();
                    }
                },
                options: [
                    'connection' => $connection,
                ]
            );
        } catch (\Exception $e) {
            $this->error("Error processing Salesforce orders: " . $e->getMessage());
            Log::error('Salesforce order consumer error', [
                'error' => $e->getMessage(),
                'queue' => $queue,
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
