<?php

declare(strict_types=1);

use App\Support\Pdv\PdvDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

test('parses datetime with explicit timezone and normalizes to utc', function () {
    $parsed = PdvDateTime::parseToUtc('2026-02-10T21:12:56-03:00');

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class);
    expect($parsed?->toDateTimeString())->toBe('2026-02-11 00:12:56');
    expect($parsed?->getTimezone()->getName())->toBe('UTC');
});

test('parses naive datetime using configured timezone and normalizes to utc', function () {
    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'pdv' => [
            'naive_datetime_timezone' => 'America/Sao_Paulo',
        ],
    ]));

    $parsed = PdvDateTime::parseToUtc('2026-02-10T21:12:56');

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class);
    expect($parsed?->toDateTimeString())->toBe('2026-02-11 00:12:56');
    expect($parsed?->getTimezone()->getName())->toBe('UTC');
});

test('returns null for invalid datetime', function () {
    expect(PdvDateTime::parseToUtc('not-a-date'))->toBeNull();
});
