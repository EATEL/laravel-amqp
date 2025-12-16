<?php

namespace Rev\Amqp;

use Rev\Amqp\Console\ConsumeCommand;
use Rev\Amqp\Console\HealthCommand;
use Rev\Amqp\Console\InstallCommand;
use Rev\Amqp\Contracts\Amqp as AmqpContract;
use Rev\Amqp\Exceptions\AmqpConfigException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AmqpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('amqp')
            ->hasConfigFile()
            ->hasCommands([
                ConsumeCommand::class,
                InstallCommand::class,
                HealthCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AmqpContract::class, function ($app) {
            $config = $app['config']['amqp'] ?? [];

            $this->validateConfig($config);

            return new Amqp(config: $config);
        });

        $this->app->alias(AmqpContract::class, 'amqp');
        $this->app->alias(AmqpContract::class, Amqp::class);
    }

    public function packageBooted(): void
    {
        $this->app->terminating(function () {
            if ($this->app->resolved(AmqpContract::class)) {
                $this->app->make(AmqpContract::class)->closeConnections();
            }
        });
    }

    /**
     * Validate the AMQP configuration.
     *
     * @throws AmqpConfigException
     */
    protected function validateConfig(array $config): void
    {
        $connection = $config['connection'] ?? [];

        if (! empty($connection['url'])) {
            return;
        }

        if (empty($connection['host'])) {
            throw AmqpConfigException::missingConfiguration();
        }
    }
}