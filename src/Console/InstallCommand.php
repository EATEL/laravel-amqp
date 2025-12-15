<?php

namespace Rev\Amqp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use Rev\Amqp\Support\ConnectionUrlParser;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'amqp:install 
                            {--force : Overwrite existing configuration}';

    protected $description = 'Install and configure Laravel AMQP';

    protected array $connectionConfig = [];

    public function handle(): int
    {
        intro('Laravel AMQP - Easy RabbitMQ integration');

        $this->publishConfiguration();

        if (! $this->option('no-interaction')) {
            $this->gatherConnectionDetails();
        } else {
            $this->useDefaults();
        }

        if (! $this->option('no-interaction') && confirm('Would you like to test the connection?', default: true)) {
            $success = $this->testConnection();
            if (! $success) {
                if (! confirm('Connection failed. Continue anyway?', default: false)) {
                    return self::FAILURE;
                }
            }
        }

        if (! $this->option('no-interaction') && confirm('Create an AMQP consumer service provider?', default: true)) {
            $this->createConsumerServiceProvider();
        }

        $this->updateEnvExample();

        $this->showSuccessMessage();

        return self::SUCCESS;
    }

    protected function publishConfiguration(): void
    {
        $configPath = config_path('amqp.php');

        if (File::exists($configPath) && ! $this->option('force')) {
            info('Configuration file already exists.');

            return;
        }

        spin(
            callback: function () {
                $this->callSilently('vendor:publish', [
                    '--tag' => 'amqp-config',
                    '--force' => $this->option('force'),
                ]);
            },
            message: 'Publishing configuration...'
        );

        info('Configuration published successfully.');
    }

    protected function gatherConnectionDetails(): void
    {
        $method = select(
            label: 'How would you like to configure your connection?',
            options: [
                'url' => 'Connection URL (recommended)',
                'individual' => 'Individual settings',
            ],
            default: 'url'
        );

        if ($method === 'url') {
            $this->gatherUrlConfig();
        } else {
            $this->gatherIndividualConfig();
        }
    }

    protected function gatherUrlConfig(): void
    {
        $url = text(
            label: 'Enter your AMQP connection URL',
            placeholder: 'amqp://user:password@localhost:5672/',
            hint: 'Format: amqp://user:password@host:port/vhost',
            validate: function (string $value) {
                if (empty($value)) {
                    return 'URL is required';
                }
                if (! str_starts_with($value, 'amqp://') && ! str_starts_with($value, 'amqps://')) {
                    return 'URL must start with amqp:// or amqps://';
                }

                return null;
            }
        );

        $this->connectionConfig = ConnectionUrlParser::parse($url);
        $this->connectionConfig['url'] = $url;
    }

    protected function gatherIndividualConfig(): void
    {
        $this->connectionConfig['host'] = text(
            label: 'RabbitMQ host',
            default: 'localhost',
            hint: 'The hostname or IP address of your RabbitMQ server'
        );

        $this->connectionConfig['port'] = (int) text(
            label: 'RabbitMQ port',
            default: '5672',
            validate: fn (string $value) => is_numeric($value) ? null : 'Port must be a number'
        );

        $this->connectionConfig['user'] = text(
            label: 'Username',
            default: 'guest'
        );

        $this->connectionConfig['password'] = password(
            label: 'Password',
            hint: 'Leave empty for default "guest"'
        ) ?: 'guest';

        $this->connectionConfig['vhost'] = text(
            label: 'Virtual host',
            default: '/',
            hint: 'Usually "/" for the default vhost'
        );

        $this->connectionConfig['url'] = ConnectionUrlParser::build($this->connectionConfig);
    }

    protected function useDefaults(): void
    {
        $this->connectionConfig = [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'url' => 'amqp://guest:guest@localhost:5672/',
        ];
    }

    protected function testConnection(): bool
    {
        return spin(
            callback: function () {
                try {
                    $config = new AMQPConnectionConfig();
                    $config->setHost($this->connectionConfig['host']);
                    $config->setPort($this->connectionConfig['port']);
                    $config->setUser($this->connectionConfig['user']);
                    $config->setPassword($this->connectionConfig['password']);
                    $config->setVhost($this->connectionConfig['vhost']);
                    $config->setConnectionTimeout(5);

                    $isSecure = ($this->connectionConfig['ssl']['enabled'] ?? false)
                        || ! in_array($this->connectionConfig['host'], ['localhost', '127.0.0.1']);
                    $config->setIsSecure($isSecure);

                    $connection = AMQPConnectionFactory::create($config);
                    $connection->close();

                    info('Connection successful!');

                    return true;
                } catch (\Throwable $e) {
                    error('Connection failed: '.$e->getMessage());

                    return false;
                }
            },
            message: 'Testing connection...'
        );
    }

    protected function createConsumerServiceProvider(): void
    {
        $providerPath = app_path('Providers/AmqpServiceProvider.php');

        if (File::exists($providerPath) && ! $this->option('force')) {
            info('AmqpServiceProvider already exists.');

            return;
        }

        $stub = File::get(__DIR__.'/../../stubs/amqp-consumer-provider.stub');

        $content = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            ['App\\Providers', 'AmqpServiceProvider'],
            $stub
        );

        File::ensureDirectoryExists(dirname($providerPath));
        File::put($providerPath, $content);

        info('Created app/Providers/AmqpServiceProvider.php');
        note('Register it in bootstrap/providers.php (Laravel 11+) or config/app.php');
    }

    protected function updateEnvExample(): void
    {
        $envExamplePath = base_path('.env.example');

        if (! File::exists($envExamplePath)) {
            return;
        }

        $contents = File::get($envExamplePath);

        if (str_contains($contents, 'AMQP_URL') || str_contains($contents, 'AMQP_HOST')) {
            return;
        }

        $amqpConfig = "\n# AMQP / RabbitMQ\nAMQP_URL=\n";

        File::append($envExamplePath, $amqpConfig);
        info('Added AMQP configuration to .env.example');
    }

    protected function showSuccessMessage(): void
    {
        $envLine = isset($this->connectionConfig['url'])
            ? "AMQP_URL={$this->connectionConfig['url']}"
            : "AMQP_HOST={$this->connectionConfig['host']}";

        note(<<<NOTE
        AMQP configured successfully!

        Add to your .env file:
          {$envLine}
        NOTE);

        outro('Setup complete!');
    }
}
