<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
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
 * bootstrap/app.php's withExceptions() turns request-forgery failures into a
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
        $handler = app(ExceptionHandler::class);

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
        // render() in bootstrap/app.php this would be a bare 403 error page.
        $handler = app(ExceptionHandler::class);

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
        $handler = app(ExceptionHandler::class);

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
        $handler = app(ExceptionHandler::class);

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
}
