<?php

use Rev\Amqp\Support\ConnectionUrlParser;

describe('ConnectionUrlParser', function () {
    it('parses basic amqp url', function () {
        $result = ConnectionUrlParser::parse('amqp://guest:guest@localhost:5672/');

        expect($result)->toMatchArray([
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ]);
        expect($result['ssl']['enabled'])->toBeFalse();
    });

    it('parses amqps url with ssl enabled', function () {
        $result = ConnectionUrlParser::parse('amqps://user:pass@rabbit.example.com:5671/production');

        expect($result)->toMatchArray([
            'host' => 'rabbit.example.com',
            'port' => 5671,
            'user' => 'user',
            'password' => 'pass',
            'vhost' => 'production',
        ]);
        expect($result['ssl']['enabled'])->toBeTrue();
    });

    it('uses default port 5672 for amqp', function () {
        $result = ConnectionUrlParser::parse('amqp://guest:guest@localhost/');

        expect($result['port'])->toBe(5672);
    });

    it('uses default port 5671 for amqps', function () {
        $result = ConnectionUrlParser::parse('amqps://guest:guest@localhost/');

        expect($result['port'])->toBe(5671);
    });

    it('decodes url-encoded credentials', function () {
        $result = ConnectionUrlParser::parse('amqp://my%40user:p%40ss%2Fword@localhost:5672/');

        expect($result['user'])->toBe('my@user');
        expect($result['password'])->toBe('p@ss/word');
    });

    it('decodes url-encoded vhost', function () {
        $result = ConnectionUrlParser::parse('amqp://guest:guest@localhost:5672/my%2Fvhost');

        expect($result['vhost'])->toBe('my/vhost');
    });

    it('handles empty path as root vhost', function () {
        $result = ConnectionUrlParser::parse('amqp://guest:guest@localhost:5672');

        expect($result['vhost'])->toBe('/');
    });

    it('handles trailing slash as root vhost', function () {
        $result = ConnectionUrlParser::parse('amqp://guest:guest@localhost:5672/');

        expect($result['vhost'])->toBe('/');
    });

    it('uses defaults for missing parts', function () {
        $result = ConnectionUrlParser::parse('amqp://localhost');

        expect($result['host'])->toBe('localhost');
        expect($result['port'])->toBe(5672);
        expect($result['user'])->toBe('guest');
        expect($result['password'])->toBe('guest');
        expect($result['vhost'])->toBe('/');
    });

    it('builds url from config', function () {
        $config = [
            'host' => 'rabbit.example.com',
            'port' => 5672,
            'user' => 'myapp',
            'password' => 'secret',
            'vhost' => 'production',
            'ssl' => ['enabled' => false],
        ];

        $url = ConnectionUrlParser::build($config);

        expect($url)->toBe('amqp://myapp:secret@rabbit.example.com:5672/production');
    });

    it('builds amqps url when ssl enabled', function () {
        $config = [
            'host' => 'rabbit.example.com',
            'port' => 5671,
            'user' => 'myapp',
            'password' => 'secret',
            'vhost' => '/',
            'ssl' => ['enabled' => true],
        ];

        $url = ConnectionUrlParser::build($config);

        expect($url)->toBe('amqps://myapp:secret@rabbit.example.com:5671');
    });

    it('encodes special characters in built url', function () {
        $config = [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'my@user',
            'password' => 'p@ss/word',
            'vhost' => '/',
        ];

        $url = ConnectionUrlParser::build($config);

        expect($url)->toBe('amqp://my%40user:p%40ss%2Fword@localhost:5672');
    });

    it('throws exception for invalid url', function () {
        ConnectionUrlParser::parse('not-a-valid-url');
    })->throws(InvalidArgumentException::class);
});
