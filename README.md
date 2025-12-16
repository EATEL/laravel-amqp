# Laravel AMQP

A clean Laravel wrapper for direct AMQP messaging with RabbitMQ.

## Installation

Install the package via Composer:
Add the following to the root of your `composer.json`:
```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/eatel/laravel-amqp"
    }
]
```

and then run

```bash
composer require rev/laravel-amqp@1.2.0
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Rev\Amqp\AmqpServiceProvider"
```

## Configuration

Configure your RabbitMQ connection using environment variables. Either `AMQP_URL` or `AMQP_HOST` is required.

```env
# Connection URL (recommended) - Required if not using individual settings
AMQP_URL=amqp://user:password@host:port/vhost

# Or individual settings - AMQP_HOST required if not using URL
AMQP_HOST=localhost  # Required
AMQP_PORT=5672       # Optional, default: 5672
AMQP_USER=guest      # Optional, default: guest
AMQP_PASSWORD=guest  # Optional, default: guest
AMQP_VHOST=/         # Optional, default: /

# Connection settings - All optional with defaults
AMQP_HEARTBEAT=60              # Default: 60
AMQP_CONNECTION_TIMEOUT=10     # Default: 10
AMQP_READ_TIMEOUT=10           # Default: 10
AMQP_WRITE_TIMEOUT=10          # Default: 10

# SSL configuration - All optional
AMQP_SSL=false                 # Default: false
AMQP_SSL_VERIFY_PEER=true      # Default: true
AMQP_SSL_CAFILE=/path/to/ca-cert.pem  # Optional

# Retry configuration - All optional with defaults
AMQP_RETRY_MAX_ATTEMPTS=5      # Default: 5
AMQP_RETRY_INITIAL_DELAY=1     # Default: 1
AMQP_RETRY_MAX_DELAY=30        # Default: 30
AMQP_RETRY_MULTIPLIER=2        # Default: 2

# Consumer defaults - All optional with defaults
AMQP_PREFETCH_COUNT=1          # Default: 1
AMQP_CONSUME_TIMEOUT=0         # Default: 0
AMQP_ON_ERROR=requeue          # Default: requeue

# Logging - All optional with defaults
AMQP_LOGGING_ENABLED=true      # Default: true
AMQP_LOG_CHANNEL=              # Optional
```

## Publishing Messages

Use the `Amqp` facade to publish messages to an exchange:

```php
use Rev\Amqp\Amqp;

// Basic publish with auto-generated message id
$messageId = Amqp::publish(['key' => 'value'], 'my_exchange', 'routing.key');

// With custom message properties
$messageId = rand(0, 1e6)
Amqp::publish(
    payload: ['user_id' => 123, 'action' => 'login'],
    exchange: 'user_events',
    routingKey: 'user.login',
    messageProperties: [
        'content_type' => 'application/json',
        'delivery_mode' => 2, // Persistent
        'priority' => 5,
        'message_id' => $messageId
    ],
    publishOptions: [
        'mandatory' => true,
    ]
);
```

The payload will be automatically JSON-encoded.

## Consuming Messages

Use the `Amqp` facade to consume messages from a queue:

```php
use Rev\Amqp\Amqp;

Amqp::consume('my_queue', function (array $payload, \PhpAmqpLib\Message\AMQPMessage $message) {
    // Process the message
    echo "Received: " . json_encode($payload);

    // Return true to continue consuming
    // Return false to stop consuming
    return true;
});
```

### Message Acknowledgment

Messages are automatically acknowledged after successful processing. To reject a message, throw an exception from the callback:

```php
Amqp::consume('my_queue', function (array $payload, \PhpAmqpLib\Message\AMQPMessage $message) {
    if ($payload['invalid']) {
        throw new \Exception('Invalid message');
        // Message will be rejected and not requeued
    }

    // Process valid message...
    return true;
});
```

### Consumer Options

Pass options to customize consumer behavior:

```php
Amqp::consume('my_queue', function ($payload, $message) {
    // Process message
    return true;
}, [
    'consumer_tag' => 'my-consumer',
    'no_local' => false,
    'no_ack' => false,
    'exclusive' => false,
    'nowait' => false,
]);
```

## Event-Based Logging

The package provides comprehensive event-based logging for both publishing and consuming operations. Events are always dispatched, allowing applications to implement custom logging logic through Laravel's event system.

### Consumer Events

The following events are dispatched during message consumption:

- `Rev\Amqp\Events\MessageReceived` - Dispatched when a message is received and about to begin processing
- `Rev\Amqp\Events\MessageProcessed` - Dispatched after successful processing
- `Rev\Amqp\Events\MessageFailed` - Dispatched when processing fails
- `Rev\Amqp\Events\ConsumerStarted` - Dispatched when consumption begins
- `Rev\Amqp\Events\ConsumerStopped` - Dispatched when consumption ends

### Publish Events

- `Rev\Amqp\Events\MessagePublished` - Dispatched for each publish operation

### Usage Examples

**Consumer Logging:**
```php
use Rev\Amqp\Events\MessageReceived;
use Rev\Amqp\Events\MessageFailed;

// Log all received messages
Event::listen(MessageReceived::class, function (MessageReceived $event) {
    Log::info('AMQP Message received', [
        'queue' => $event->queue,
        'payload' => $event->payload,
    ]);
});

// Log processing failures
Event::listen(MessageFailed::class, function (MessageFailed $event) {
    Log::error('AMQP Message processing failed', [
        'queue' => $event->queue,
        'error' => $event->exception->getMessage(),
        'payload' => $event->payload,
    ]);
});
```

**Publish Logging:**
```php
use Rev\Amqp\Events\MessagePublished;

// Log all publishes
Event::listen(MessagePublished::class, function (MessagePublished $event) {
    Log::info('AMQP Message published', [
        'exchange' => $event->exchange,
        'routing_key' => $event->routingKey,
        'payload' => $event->payload,
    ]);
});
```

Events provide maximum flexibility - applications can implement any logging logic, monitoring, or additional processing they need without modifying the core AMQP functionality.

## Available Commands

The package provides several Artisan commands:

```bash
# Install the package (publish config)
php artisan amqp:install

# Check connection health
php artisan amqp:health

# Consume messages from a queue
php artisan amqp:consume my_queue --prefetch=5
```

## Internals

The package consists of:

- `Rev\Amqp\Amqp` - Main service class and facade implementing `Rev\Amqp\Contracts\Amqp`
- `Rev\Amqp\AmqpServiceProvider` - Service provider registration
- Automatic reconnection with exponential backoff
- Signal handling for graceful shutdown
