<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Event;
use App\Models\OutboxJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Resolve P7 ("painel lento com 30 pessoas atualizando"): o custo desta rota
 * NÃO cresce com o número de visualizadores nem com o número de pedidos.
 *  - Lê só os CONTADORES já mantidos em `batches` (reserved/sold/revenue),
 *    nunca faz COUNT/SUM em `orders`. O custo é O(nº de lotes).
 *  - O resultado fica em cache no Redis por 1s (Cache::flexible evita
 *    "stampede": só uma requisição de fato recalcula, as outras esperam
 *    o mesmo resultado em vez de disparar 30 queries simultâneas).
 *  - ETag calculado SEM o timestamp `generated_at`, para permitir 304
 *    quando os números não mudaram (a maior parte do tempo entre vendas).
 *  - Nunca usa SSE/long-polling: cada conexão pendurada prenderia um worker
 *    do php-fpm (pool pequeno) sem fazer nada — polling curto + 304 é mais
 *    barato aqui do que manter conexões abertas.
 */
class DashboardController
{
    public function index(Request $request)
    {
        $snapshot = Cache::flexible('admin:dashboard:snapshot', [1, 5], function () {
            $events = Event::with('batches')->get()->map(function ($event) {
                $batches = $event->batches->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'price_cents' => $b->price_cents,
                    'total' => $b->total,
                    'sold' => $b->sold,
                    'pending' => $b->reserved,
                    'available' => $b->available(),
                    'revenue_cents' => $b->revenue_cents,
                ]);

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'totals' => [
                        'sold' => $batches->sum('sold'),
                        'pending' => $batches->sum('pending'),
                        'available' => $batches->sum('available'),
                        'revenue_cents' => $batches->sum('revenue_cents'),
                    ],
                    'batches' => $batches,
                ];
            });

            $jobs = [
                'pending' => OutboxJob::where('status', OutboxJob::STATUS_PENDING)->count(),
                'running' => OutboxJob::where('status', OutboxJob::STATUS_RUNNING)->count(),
                'failed' => OutboxJob::where('status', OutboxJob::STATUS_FAILED)->count(),
            ];

            return ['events' => $events, 'jobs' => $jobs];
        });

        $etag = '"'.md5(json_encode($snapshot)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', 'private, max-age=1');
        }

        return response()->json(['generated_at' => now()->toIso8601String()] + $snapshot)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=1');
    }
}
