<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Covers the app's CUSTOM request-forgery behaviour.
 *
 * App\Exceptions\Handler::render() turns request-forgery failures into a
 * friendly back()->with('message_csrf', ...) redirect, which
 * resources/js/components/Form/FormDialog.vue renders as an amber banner.
 *
 * This is the highest-risk code in the Laravel 13 upgrade. L13 renamed
 * VerifyCsrfToken -> PreventRequestForgery and added Sec-Fetch-Site request
 * origin verification, which introduces a SECOND failure mode:
 * OriginMismatchException. The framework renders that as a 403, not a 419,
 * so a status-code check alone would silently miss it and users would get a
 * raw error page instead of the banner.
 *
 * The middleware itself short-circuits under `runningUnitTests()`, so the
 * tests that exercise the real origin logic rebind the container's `env`
 * binding to opt out of that shortcut.
 */
class CsrfExpiryTest extends TestCase
{
    private const MESSAGE = 'The page expired, please try again.';

    /**
     * Run a callback with the framework's unit-test shortcut disabled, so the
     * forgery middleware actually enforces its checks.
     */
    private function withoutUnitTestShortcut(callable $callback): mixed
    {
        // Application::runningUnitTests() is `bound('env') && env === 'testing'`.
        app()->instance('env', 'production');

        try {
            return $callback();
        } finally {
            app()->instance('env', 'testing');
        }
    }

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

    public function test_handler_converts_an_origin_mismatch_into_the_same_redirect(): void
    {
        // OriginMismatchException is the L13 Sec-Fetch-Site failure. The
        // framework maps it to 403, so without the explicit branch in
        // Handler::render() this would render as a bare 403 error page.
        $handler = app(Handler::class);

        $response = $handler->render(
            Request::create('/accounts', 'POST'),
            new OriginMismatchException('Origin mismatch.')
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::MESSAGE, session()->get('message_csrf'));
    }

    public function test_handler_leaves_genuine_forbidden_responses_alone(): void
    {
        // The override keys off the exception type, not the 403 status, so a
        // real authorization failure must keep its own response.
        $handler = app(Handler::class);

        $response = $handler->render(
            Request::create('/accounts', 'POST'),
            new AccessDeniedHttpException('This action is unauthorized.')
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull(session()->get('message_csrf'));
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

    public function test_same_origin_header_satisfies_forgery_protection_without_a_token(): void
    {
        // L13 short-circuits the token comparison when the browser reports
        // Sec-Fetch-Site: same-origin. Without this, every legitimate Inertia
        // write would 419 on any request that omits the token.
        Route::middleware('web')->post('/__test__/echo', fn () => 'ok');

        $response = $this->withoutUnitTestShortcut(fn () => $this
            ->withSession([])
            ->post('/__test__/echo', [], ['Sec-Fetch-Site' => 'same-origin'])
        );

        $response->assertStatus(200);
        $this->assertSame('ok', $response->getContent());
    }

    public function test_cross_site_write_without_a_valid_token_is_rejected(): void
    {
        Route::middleware('web')->post('/__test__/echo', fn () => 'ok');

        $response = $this->withoutUnitTestShortcut(fn () => $this
            ->from('/accounts')
            ->withSession([])
            ->post('/__test__/echo', [], ['Sec-Fetch-Site' => 'cross-site'])
        );

        // Reaches the app's custom handler and comes back as the friendly
        // redirect, not a raw 419.
        $response->assertStatus(302);
        $response->assertRedirect('/accounts');
        $response->assertSessionHas('message_csrf', self::MESSAGE);
    }

    public function test_get_requests_are_never_blocked_by_forgery_protection(): void
    {
        Route::middleware('web')->get('/__test__/echo', fn () => 'ok');

        $response = $this->withoutUnitTestShortcut(fn () => $this
            ->get('/__test__/echo', ['Sec-Fetch-Site' => 'cross-site'])
        );

        $response->assertStatus(200);
    }

    public function test_csrf_middleware_is_registered_on_the_web_group(): void
    {
        // Guards the L13 rename: the web group must still have exactly one
        // forgery-protection middleware in it, and it must be the renamed class.
        $kernel = app(Kernel::class);

        $middleware = method_exists($kernel, 'getMiddlewareGroups')
            ? $kernel->getMiddlewareGroups()['web']
            : $kernel->getGlobalMiddleware();

        $forgery = array_values(array_filter($middleware, function ($class) {
            return $this->isForgeryMiddleware($class);
        }));

        $this->assertCount(
            1,
            $forgery,
            'Expected exactly one request-forgery middleware in the web group, got: '
                .implode(', ', $forgery)
        );

        $this->assertSame(
            PreventRequestForgery::class,
            $forgery[0],
            'The web group must reference App\Http\Middleware\PreventRequestForgery. '
                .'Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery and left the '
                .'old name as a deprecated alias, so a stale reference still works but '
                .'is no longer correct.'
        );
    }

    public function test_app_middleware_extends_the_laravel_13_base_class(): void
    {
        // The app subclass exists only to hold $except, so it must track the
        // framework class or it would silently stop enforcing anything.
        $this->assertTrue(
            is_a(PreventRequestForgery::class, \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, true)
        );
    }

    private function isForgeryMiddleware(string $class): bool
    {
        return $class === PreventRequestForgery::class
            || is_a($class, \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, true)
            || is_a($class, ValidateCsrfToken::class, true)
            || is_a($class, VerifyCsrfToken::class, true);
    }
}
