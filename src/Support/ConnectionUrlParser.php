<?php

namespace Rev\Amqp\Support;

class ConnectionUrlParser
{
    /**
     * Parse AMQP URL into connection config array.
     *
     * Format: amqp://user:password@host:port/vhost
     * Format: amqps://user:password@host:port/vhost (SSL)
     *
     * Examples:
     *   amqp://guest:guest@localhost:5672/
     *   amqps://myapp:secret@rabbitmq.example.com:5671/production
     */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme']) || ! isset($parts['host'])) {
            throw new \InvalidArgumentException("Invalid AMQP URL: {$url}");
        }

        $scheme = $parts['scheme'];
        $isSecure = $scheme === 'amqps';

        $vhost = '/';
        if (isset($parts['path'])) {
            $path = ltrim($parts['path'], '/');
            $vhost = $path === '' ? '/' : urldecode($path);
        }

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? ($isSecure ? 5671 : 5672),
            'user' => isset($parts['user']) ? urldecode($parts['user']) : 'guest',
            'password' => isset($parts['pass']) ? urldecode($parts['pass']) : 'guest',
            'vhost' => $vhost,
            'ssl' => [
                'enabled' => $isSecure,
            ],
        ];
    }

    public static function build(array $config): string
    {
        $scheme = ($config['ssl']['enabled'] ?? false) ? 'amqps' : 'amqp';
        $user = urlencode($config['user'] ?? 'guest');
        $password = urlencode($config['password'] ?? 'guest');
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5672;
        $vhost = $config['vhost'] ?? '/';

        $vhostPath = $vhost === '/' ? '' : '/'.urlencode($vhost);

        return "{$scheme}://{$user}:{$password}@{$host}:{$port}{$vhostPath}";
    }
}
