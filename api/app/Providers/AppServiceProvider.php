<?php

namespace App\Providers;

use App\Contracts\FinanceClient;
use App\Contracts\OrderNotifier;
use App\Contracts\TicketRenderer;
use App\Infrastructure\DompdfTicketRenderer;
use App\Infrastructure\HttpFinanceClient;
use App\Infrastructure\MailOrderNotifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinanceClient::class, HttpFinanceClient::class);
        $this->app->bind(TicketRenderer::class, DompdfTicketRenderer::class);
        $this->app->bind(OrderNotifier::class, MailOrderNotifier::class);
    }

    public function boot(): void
    {
        // 0 (ou negativo) desliga o limite: usado em teste de carga e nos testes.
        RateLimiter::for('orders', function (Request $request) {
            $limit = (int) config('tickets.orders_rate_limit_per_minute', 60);

            return $limit > 0 ? Limit::perMinute($limit)->by($request->ip()) : Limit::none();
        });

        RateLimiter::for('login', function (Request $request) {
            $limit = (int) config('tickets.login_rate_limit_per_minute', 10);

            return $limit > 0
                ? Limit::perMinute($limit)->by(strtolower((string) $request->input('email')).'|'.$request->ip())
                : Limit::none();
        });
    }
}
