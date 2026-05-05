<?php

declare(strict_types=1);

/**
 * Sanity check that the test suite is actually pinned to sqlite :memory:.
 * If this fails, the safety guard in TestCase has been weakened or phpunit.xml
 * is misconfigured — abort and investigate before touching real data.
 */
it('runs against sqlite :memory: and not the real database', function (): void {
    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");
    $database = config("database.connections.{$connection}.database");

    expect($driver)->toBe('sqlite')
        ->and($database)->toBe(':memory:');
});
