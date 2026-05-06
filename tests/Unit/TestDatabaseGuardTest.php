<?php

declare(strict_types=1);

it('runs against sqlite :memory: and not the real database', function (): void {
    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");
    $database = config("database.connections.{$connection}.database");

    expect($driver)->toBe('sqlite')
        ->and($database)->toBe(':memory:');
});
