<?php

namespace App\Http\Controllers\Api\Admin;

use App\Console\Commands\Gateway\GatewayEventDispatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Só existe com ENABLE_DEV_TOOLS=true e apenas para admin. Alimenta a tela
 * /admin/gateway do front com os mesmos cenários dos comandos gateway:*.
 */
class DevGatewayController extends Controller
{
    public function handle(Request $request, string $action, GatewayEventDispatcher $dispatcher)
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'times' => ['sometimes', 'integer', 'min:2', 'max:20'],
        ]);
        $orderId = $data['order_id'];

        $result = match ($action) {
            'approve' => $dispatcher->send($orderId, 'approved'),
            'decline' => $dispatcher->send($orderId, 'declined'),
            'refund' => $dispatcher->send($orderId, 'refunded'),
            'duplicate' => $dispatcher->sendDuplicated($orderId, 'approved', $data['times'] ?? 5),
            'out-of-order' => $dispatcher->sendOutOfOrder($orderId),
            'retry-on-timeout' => $dispatcher->sendRetryOnTimeout($orderId),
        };

        return response()->json(['ok' => true, 'result' => $result]);
    }
}
