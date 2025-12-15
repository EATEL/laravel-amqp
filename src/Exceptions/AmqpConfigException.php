<?php

namespace Rev\Amqp\Exceptions;

use RuntimeException;

class AmqpConfigException extends RuntimeException
{
    /**
     * Create a new exception for missing configuration.
     */
    public static function missingConfiguration(): self
    {
        return new self(
            "AMQP connection not configured.\n\n".
            "Add to your .env file:\n".
            "  AMQP_URL=amqp://user:password@host:5672/vhost\n\n".
            "Or use individual settings:\n".
            "  AMQP_HOST=localhost\n".
            "  AMQP_USER=guest\n".
            "  AMQP_PASSWORD=guest\n\n".
            "Run 'php artisan amqp:install' for interactive setup."
        );
    }
}
