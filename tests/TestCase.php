<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // A request no fake matches fails the test rather than reaching Yahoo: a live answer
        // passes or fails by the day's prices, and the suite would depend on the network.
        Http::preventStrayRequests();
    }
}
