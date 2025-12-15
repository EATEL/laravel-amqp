<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AMQP Connection
    |--------------------------------------------------------------------------
    |
    | Connection settings for RabbitMQ. You can use either a connection URL
    | (recommended) or individual settings. The URL takes precedence if set.
    |
    | URL format: amqp://user:password@host:port/vhost
    | SSL format: amqps://user:password@host:port/vhost
    |
    */

    'connection' => [
        'url' => env('AMQP_URL'),

        'host' => env('AMQP_HOST', 'localhost'),
        'port' => env('AMQP_PORT', 5672),
        'user' => env('AMQP_USER', 'guest'),
        'password' => env('AMQP_PASSWORD', 'guest'),
        'vhost' => env('AMQP_VHOST', '/'),

        'heartbeat' => env('AMQP_HEARTBEAT', 60),
        'connection_timeout' => env('AMQP_CONNECTION_TIMEOUT', 10),
        'read_timeout' => env('AMQP_READ_TIMEOUT', 10),
        'write_timeout' => env('AMQP_WRITE_TIMEOUT', 10),

        'ssl' => [
            'enabled' => env('AMQP_SSL', false),
            'verify_peer' => env('AMQP_SSL_VERIFY_PEER', true),
            'cafile' => env('AMQP_SSL_CAFILE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic reconnection attempts when connection is lost.
    | Uses exponential backoff with optional jitter.
    |
    */

    'retry' => [
        'max_attempts' => env('AMQP_RETRY_MAX_ATTEMPTS', 5),
        'initial_delay' => env('AMQP_RETRY_INITIAL_DELAY', 1),
        'max_delay' => env('AMQP_RETRY_MAX_DELAY', 30),
        'multiplier' => env('AMQP_RETRY_MULTIPLIER', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for message consumers. These can be overridden
    | per-consume call via the options array.
    |
    */

    'consume' => [
        'prefetch_count' => env('AMQP_PREFETCH_COUNT', 1),
        'timeout' => env('AMQP_CONSUME_TIMEOUT', 0),
        'on_error' => env('AMQP_ON_ERROR', 'requeue'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for AMQP operations. Set enabled to false to
    | disable all AMQP logging.
    |
    */

    'logging' => [
        'enabled' => env('AMQP_LOGGING_ENABLED', true),
        'channel' => env('AMQP_LOG_CHANNEL'),
    ],
];
