<?php

use Rev\Amqp\Facades\Amqp;

it('can fake amqp publishing', function () {
    Amqp::fake();

    Amqp::publish(['order_id' => 123], 'orders', 'order.created');

    Amqp::assertPublished('orders', 'order.created');
    Amqp::assertPublishedCount(1);
});

it('can assert nothing published', function () {
    Amqp::fake();

    Amqp::assertNothingPublished();
});

it('can fake rpc responses', function () {
    $fake = Amqp::fake();
    $fake->fakeRpcResponse('users', 'user.get', ['id' => 1, 'name' => 'John']);

    $response = Amqp::rpc(['user_id' => 1], 'users', 'user.get');

    expect($response)->toBe(['id' => 1, 'name' => 'John']);
});

it('can publish to queue', function () {
    Amqp::fake();

    Amqp::publishToQueue(['task' => 'send-email'], 'email-queue');

    Amqp::assertPublishedTo('email-queue');
});

it('can assert with callback', function () {
    Amqp::fake();

    Amqp::publish(['order_id' => 456, 'status' => 'pending'], 'orders', 'order.created');

    Amqp::assertPublished('orders', 'order.created', function ($payload) {
        return $payload['order_id'] === 456 && $payload['status'] === 'pending';
    });
});
