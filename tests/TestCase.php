<?php

namespace Rev\Amqp\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rev\Amqp\AmqpServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AmqpServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('amqp.connection.host', 'localhost');
        config()->set('amqp.connection.port', 5672);
        config()->set('amqp.connection.user', 'guest');
        config()->set('amqp.connection.password', 'guest');
        config()->set('amqp.connection.vhost', '/');
    }
}
