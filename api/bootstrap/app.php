<?php

use App\Http\ApiError;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\DevToolsEnabled;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        $middleware->alias([
            'dev-tools-enabled' => DevToolsEnabled::class,
            'admin-access' => AdminAccess::class,
        ]);

        // throttleWithRedis só faz sentido (e só funciona) quando o cache store é Redis.
        if (env('CACHE_STORE', 'redis') === 'redis') {
            $middleware->throttleWithRedis();
        }
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Captura falhas de autenticação (401 Unauthorized) e evita redirecionamentos para 'login'
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiError::make('unauthenticated', 'Não autenticado.', 401);
            }
        });

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return ApiError::make('validation_failed', 'Dados inválidos.', 422, fields: $e->errors());
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return ApiError::make('not_found', 'Recurso não encontrado.', 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return ApiError::make('not_found', 'Recurso não encontrado.', 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                return ApiError::make('rate_limited', 'Muitas requisições, tente novamente em instantes.', 429, retryAfter: 5);
            }
        });

        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            // Erros HTTP "esperados" (405, 403, 413...) mantêm o seu status.
            if ($e instanceof HttpExceptionInterface) {
                return ApiError::make('http_error', $e->getMessage() ?: 'Erro na requisição.', $e->getStatusCode());
            }
            if (! app()->hasDebugModeEnabled()) {
                return ApiError::make('internal_error', 'Erro interno.', 500);
            }
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('orders:expire')->everyMinute()->withoutOverlapping();
        $schedule->command('outbox:sweep')->everyThirtySeconds()->withoutOverlapping();
        $schedule->command('reconcile:finance')->everyFiveMinutes();
    })
    ->create();
