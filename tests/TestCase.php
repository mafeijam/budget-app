<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** False for a test about signing in, which starts signed out. */
    protected bool $signedIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Unsaved, so a test that builds no database still builds none. Nothing reads the
        // users table but signing in, and auth.session skips a user with no password.
        if ($this->signedIn) {
            $this->actingAs(new User(['name' => 'Test']));
        }

        // A request no fake matches fails the test rather than reaching Yahoo: a live answer
        // passes or fails by the day's prices, and the suite would depend on the network.
        Http::preventStrayRequests();
    }
}
