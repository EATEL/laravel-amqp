<?php

namespace Rev\Amqp\Console;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use Rev\Amqp\Support\ConnectionUrlParser;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class HealthCommand extends Command
{
    protected $signature = 'amqp:health';

    protected $description = 'Check AMQP connection health';

    public function handle(): int
    {
        intro('AMQP Health Check');

        $config = $this->getConnectionConfig();

        if (empty($config)) {
            error('AMQP not configured. Run: php artisan amqp:install');

            return self::FAILURE;
        }

        $this->displayConnectionInfo($config);

        $result = $this->testConnection($config);

        if ($result['success']) {
            info('✓ Connection successful!');

            if (isset($result['server_properties'])) {
                $this->displayServerInfo($result['server_properties']);
            }

            outro('AMQP is healthy.');

            return self::SUCCESS;
        } else {
            error('✗ Connection failed: '.$result['error']);
            outro('Check your configuration and try again.');

            return self::FAILURE;
        }
    }

    protected function getConnectionConfig(): array
    {
        $config = config('amqp.connection', []);

        if (! empty($config['url'])) {
            $parsed = ConnectionUrlParser::parse($config['url']);
            $config = array_merge($config, $parsed);
        }

        return $config;
    }

    protected function displayConnectionInfo(array $config): void
    {
        $ssl = ($config['ssl']['enabled'] ?? false) ? 'Yes' : 'No';
        $host = $config['host'] ?? 'not set';
        $port = $config['port'] ?? 'not set';

        table(
            headers: ['Setting', 'Value'],
            rows: [
                ['Host', "{$host}:{$port}"],
                ['User', $config['user'] ?? 'not set'],
                ['Virtual Host', $config['vhost'] ?? '/'],
                ['SSL', $ssl],
            ]
        );
    }

    protected function testConnection(array $config): array
    {
        return spin(
            callback: function () use ($config) {
                try {
                    $amqpConfig = new AMQPConnectionConfig();
                    $amqpConfig->setHost($config['host']);
                    $amqpConfig->setPort($config['port']);
                    $amqpConfig->setUser($config['user']);
                    $amqpConfig->setPassword($config['password']);
                    $amqpConfig->setVhost($config['vhost']);
                    $amqpConfig->setConnectionTimeout(5);

                    $isSecure = ($config['ssl']['enabled'] ?? false)
                        || ! in_array($config['host'], ['localhost', '127.0.0.1']);
                    $amqpConfig->setIsSecure($isSecure);

                    $connection = AMQPConnectionFactory::create($amqpConfig);

                    $serverProperties = $connection->getServerProperties();
                    $connection->close();

                    return [
                        'success' => true,
                        'server_properties' => $serverProperties,
                    ];
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            },
            message: 'Testing connection...'
        );
    }

    protected function displayServerInfo(array $properties): void
    {
        $version = $properties['version'][1] ?? 'unknown';
        $product = $properties['product'][1] ?? 'RabbitMQ';
        $platform = $properties['platform'][1] ?? 'unknown';

        $this->newLine();

        table(
            headers: ['Server Info', 'Value'],
            rows: [
                ['Product', $product],
                ['Version', $version],
                ['Platform', $platform],
            ]
        );
    }
}
