<?php

use App\Helpers\ApiResponse;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            SetApiLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every api/* request gets the {status, message, data} envelope on
        // error too — without this, an API consumer that forgets to send
        // Accept: application/json gets Laravel's HTML debug page instead
        // of JSON, and one that does send it still gets a raw stack-trace
        // dump (file paths, line numbers) instead of ApiResponse's shape.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // fall through to Laravel's default handling
            }

            return match (true) {
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::notFound('Resource not found.'),
                $e instanceof InvalidFilterQuery, $e instanceof InvalidSortQuery, $e instanceof InvalidIncludeQuery => ApiResponse::error($e->getMessage(), 400),
                $e instanceof ValidationException => ApiResponse::error('Validation failed.', 422, $e->errors()),
                default => ApiResponse::error(
                    config('app.debug') ? $e->getMessage() : 'Something went wrong.',
                    500,
                ),
            };
        });
    })->create();
