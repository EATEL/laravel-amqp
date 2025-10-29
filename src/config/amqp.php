<?php

// config/amqp.php - Configuration file example

return [
    /*
    |--------------------------------------------------------------------------
    | Default AMQP Connection Name
    |--------------------------------------------------------------------------
    */
    'default' => env('AMQP_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | AMQP Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'default' => [
            'host' => env('AMQP_HOST', 'localhost'),
            'port' => env('AMQP_PORT', 5672),
            'user' => env('AMQP_USER', 'guest'),
            'password' => env('AMQP_PASSWORD', 'guest'),
            'vhost' => env('AMQP_VHOST', '/'),
            
            // Connection timeouts (in seconds)
            'connection_timeout' => env('AMQP_CONNECTION_TIMEOUT', 10),
            'read_timeout' => env('AMQP_READ_TIMEOUT', 10),
            'write_timeout' => env('AMQP_WRITE_TIMEOUT', 10),
            'heartbeat' => env('AMQP_HEARTBEAT', 60),
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    | Configuration for automatic reconnection attempts
    */
    'retry' => [
        // Maximum number of retry attempts before giving up
        'max_attempts' => env('AMQP_RETRY_MAX_ATTEMPTS', 5),
        
        // Initial delay in seconds before first retry
        'initial_delay' => env('AMQP_RETRY_INITIAL_DELAY', 1),
        
        // Maximum delay in seconds between retries
        'max_delay' => env('AMQP_RETRY_MAX_DELAY', 30),
        
        // Backoff multiplier - delay increases by this factor each retry
        'backoff_multiplier' => env('AMQP_RETRY_BACKOFF_MULTIPLIER', 2),
        
        // Add randomness to delays to prevent thundering herd
        'jitter' => env('AMQP_RETRY_JITTER', true),
    ],
];