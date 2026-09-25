<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Covers the app's CUSTOM csrf-expiry behaviour.
 *
 * App\Exceptions\Handler::render() intercepts any 419 response and converts it
 * into a friendly back()->with('message_csrf', ...) redirect, which
 * resources/js/components/Form/FormDialog.vue renders as an amber banner.
 *
 * This is the highest-risk code in the Laravel 13 upgrade, because L13 renames
 * VerifyCsrfToken -> PreventRequestForgery and adds Sec-Fetch-Site request
 * origin verification that can surface 419s that never happened before.
 */
class CsrfExpiryTest extends TestCase
{
    private const MESSAGE = 'The page expired, please try again.';

    public function test_csrf_mismatch_redirects_back_with_the_friendly_message(): void
    {
        Route::middleware('web')->get('/__test__/csrf', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $response = $this->from('/accounts')->get('/__test__/csrf');

        $response->assertStatus(302);
        $response->assertRedirect('/accounts');
        $response->assertSessionHas('message_csrf', self::MESSAGE);
    }

    public function test_handler_converts_a_token_mismatch_into_a_redirect(): void
    {
        $handler = app(Handler::class);

        $response = $handler->render(
            Request::create('/accounts', 'POST'),
            new TokenMismatchException('CSRF token mismatch.')
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::MESSAGE, session()->get('message_csrf'));
    }

    public function test_handler_does_not_swallow_non_419_exceptions(): void
    {
        // The override must stay narrow: a 404 has to remain a 404.
        $handler = app(Handler::class);

        $response = $handler->render(
            Request::create('/nope', 'GET'),
            new NotFoundHttpException('Nope.')
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull(session()->get('message_csrf'));
    }

    public function test_csrf_middleware_is_registered_on_the_web_group(): void
    {
        // Guards the L13 rename: whichever class name is in play, the web group
        // must still have exactly one forgery-protection middleware in it.
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        $middleware = method_exists($kernel, 'getMiddlewareGroups')
            ? $kernel->getMiddlewareGroups()['web']
            : $kernel->getGlobalMiddleware();

        $forgery = array_filter($middleware, function ($class) {
            return $this->isForgeryMiddleware($class);
        });

        $this->assertCount(
            1,
            $forgery,
            'Expected exactly one request-forgery middleware in the web group, got: '
                . implode(', ', $forgery)
        );
    }

    private function isForgeryMiddleware(string $class): bool
    {
        return $class === \App\Http\Middleware\VerifyCsrfToken::class
            || is_a($class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, true)
            || is_a($class, \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, true);
    }
}
