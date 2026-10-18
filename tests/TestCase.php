<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // A cached config ignores phpunit.xml, so RefreshDatabase would wipe the real database.
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Config is cached (php artisan optimize). Run "php artisan optimize:clear" before the tests.');
        }

        parent::setUp();

        if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_test')) {
            throw new RuntimeException('Tests must run against a *_test database.');
        }
    }
}
