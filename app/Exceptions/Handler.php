<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * The flash message shown when a request fails request-forgery protection.
     *
     * Rendered by the amber banner in
     * resources/js/components/Form/FormDialog.vue via $page.props.message_csrf.
     */
    private const CSRF_MESSAGE = 'The page expired, please try again.';

    public function render($request, Throwable $e)
    {
        // Laravel 13 added Sec-Fetch-Site request-origin verification to the
        // forgery middleware. When origin-only checking is enabled that check
        // fails with OriginMismatchException, which the framework renders as a
        // 403 -- NOT a 419 -- so the status check below would miss it and the
        // user would get a raw error page instead of the banner. Match on the
        // exception type rather than the status code so genuine 403
        // authorization failures keep their own response.
        if ($e instanceof OriginMismatchException) {
            return back()->with(['message_csrf' => self::CSRF_MESSAGE]);
        }

        $response = parent::render($request, $e);

        if ($response->status() === 419) {
            return back()->with([
                'message_csrf' => self::CSRF_MESSAGE,
            ]);
        }

        return $response;
    }
}
