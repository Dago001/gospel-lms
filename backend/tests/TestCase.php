<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();   // rate-limiter counters must not leak between tests
        // The SPA's origin makes Sanctum treat requests as first-party (stateful, session-backed).
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }

    private bool $actingAsUsed = false;

    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->actingAsUsed = true;

        return parent::actingAs($user, $guard);
    }

    /** Each HTTP request in production starts with no cached guard state; emulate that in-process. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->app && ! $this->actingAsUsed) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function makeUser(string $role = 'student', array $attrs = []): User
    {
        $this->seedRolesOnce();

        return User::factory()->withRole($role)->create($attrs);
    }

    private function seedRolesOnce(): void
    {
        if (! \App\Models\Role::exists()) {
            $this->seedRoles();
        }
    }
}
