<?php

declare(strict_types=1);

test('health endpoint returns 200', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);
});

test('health endpoint returns status ok', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertJsonPath('data.status', 'ok');
});

test('version endpoint returns 200', function () {
    $response = $this->getJson('/api/v1/version');

    $response->assertStatus(200);
});

test('version endpoint returns api v1', function () {
    $response = $this->getJson('/api/v1/version');

    $response->assertJsonPath('data.api', 'v1');
});

// Note: Authentication tests will be added in the Auth module
// when login/register endpoints are implemented
