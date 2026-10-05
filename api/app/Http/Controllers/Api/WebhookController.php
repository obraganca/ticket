<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\ApplyPaymentEvent;
use App\Domain\Orders\PaymentEventType;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Fica bem abaixo dos 3s de timeout do gateway (item 4.2): só valida e faz uma
     * transação curta em ApplyPaymentEvent (estado + outbox). Nada de e-mail,
     * PDF ou financeiro aqui dentro.
     */
    public function __invoke(Request $request, ApplyPaymentEvent $action)
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:100'],
            'order_id' => ['required', 'uuid'],
            'type' => ['required', 'in:approved,declined,refunded'],
            'occurred_at' => ['required', 'date'],
        ]);

        if (! Order::whereKey($data['order_id'])->exists()) {
            Log::warning('webhook: pedido desconhecido', ['order_id' => $data['order_id']]);

            return ApiError::make('order_not_found', 'Pedido não encontrado.', 404);
        }

        $action->execute(
            $data['event_id'],
            $data['order_id'],
            PaymentEventType::from($data['type']),
            new \DateTimeImmutable($data['occurred_at']),
            $request->only(['event_id', 'order_id', 'type', 'occurred_at']),
        );

        return response()->json(['ok' => true]);
    }
}
