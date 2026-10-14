<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application and refuse to run against anything but the in-memory test database.
     *
     * A cached config (php artisan config:cache / optimize) makes tests ignore phpunit.xml,
     * which would let RefreshDatabase wipe the real database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests dibatalkan: environment [{$app->environment()}] / database [{$connection}:{$database}] bukan database testing. "
                .'Jalankan `php artisan config:clear` lalu ulangi.'
            );
        }

        return $app;
    }

    /**
     * Every page needs a login: tests with a database act as a super admin unless they sign in someone else.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Test Admin', 'username' => 'test-admin']));
        }
    }
}
