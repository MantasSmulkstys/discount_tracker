<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard safety net: refuse to run if the active connection points anywhere
     * other than an in-memory sqlite database.
     *
     * The test suite previously wiped real PostgreSQL data when a misconfigured
     * run hit the dev database. This guard makes that class of incident
     * impossible — if the connection is wrong, the suite aborts before any
     * migration, RefreshDatabase trait, or query can fire.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseIsIsolated();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    private function assertTestDatabaseIsIsolated(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against non-isolated database '.
                '(connection=%s, driver=%s, database=%s). Tests must use sqlite :memory:. '.
                'Check phpunit.xml DB_CONNECTION/DB_DATABASE env vars.',
                $connection,
                $driver ?? 'null',
                $database ?? 'null',
            ));
        }
    }
}
