<?php

use App\Http\Controllers\Api\Admin\AdminBatchController;
use App\Http\Controllers\Api\Admin\AdminEventController;
use App\Http\Controllers\Api\Admin\AdminTicketTypeController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\DevGatewayController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\VerifyGatewaySignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// O prefixo /api é aplicado automaticamente pelo Laravel: aqui só /v1/...
Route::prefix('v1')->group(function () {
    // Prontidão (usada no healthcheck do compose): banco + redis.
    Route::get('readyz', function () {
        try {
            DB::select('select 1');
            Redis::ping();
        } catch (Throwable $e) {
            return response()->json(['status' => 'not_ready'], 503);
        }

        return response()->json(['status' => 'ready']);
    });

    // Públicos
    Route::get('events', [EventController::class, 'index']);
    Route::get('events/{event}/batches', [EventController::class, 'batches']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');

    Route::post('webhooks/payments', WebhookController::class)
        ->middleware(VerifyGatewaySignature::class);

    // Autenticação (somente admin; sem cadastro público)
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    // Admin: 401 sem token, 403 para autenticado sem role admin
    Route::middleware(['auth:sanctum', AdminAccess::class])->prefix('admin')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('events', [AdminEventController::class, 'index']);
        Route::post('events', [AdminEventController::class, 'store']);
        Route::get('events/{event}', [AdminEventController::class, 'show']);
        Route::post('events/{event}', [AdminEventController::class, 'update']); // multipart => POST

        Route::post('events/{event}/ticket-types', [AdminTicketTypeController::class, 'store']);
        Route::put('ticket-types/{ticketType}', [AdminTicketTypeController::class, 'update']);

        Route::post('ticket-types/{ticketType}/batches', [AdminBatchController::class, 'store']);
        Route::put('batches/{batch}', [AdminBatchController::class, 'update']);
        Route::patch('batches/{batch}/toggle', [AdminBatchController::class, 'toggle']);

        Route::post('dev/gateway/{action}', [DevGatewayController::class, 'handle'])
            ->where('action', 'approve|decline|refund|duplicate|out-of-order|retry-on-timeout')
            ->middleware('dev-tools-enabled');
    });
});
