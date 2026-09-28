<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Last in the web group, after the session and bindings it reads.
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A request-forgery failure goes back to the page it came from, with the banner
        // FormDialog shows, rather than to an error page that loses the form. Two ways
        // to fail: an expired or missing token is a 419, and Laravel 13's Sec-Fetch-Site
        // check throws OriginMismatchException, which renders as a 403.
        $expired = fn () => back()->with('message_csrf', 'The page expired, please try again.');

        // The 403 is matched by what it wraps, not its status, so a real authorisation
        // 403 keeps its page. And on the HttpException, not OriginMismatchException:
        // the handler converts the latter into the former before any render callback
        // runs, so a callback typed on it is accepted and never called.
        $exceptions->render(fn (HttpException $e) => $e->getPrevious() instanceof OriginMismatchException
            ? $expired()
            : null);

        $exceptions->respond(fn (Response $response) => $response->getStatusCode() === 419
            ? $expired()
            : $response);
    })
    ->create();
