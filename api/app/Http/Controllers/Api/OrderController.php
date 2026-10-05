<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\BatchInactiveException;
use App\Actions\Orders\ConcurrentIdempotencyKeyInFlightException;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\IdempotencyPayloadMismatchException;
use App\Actions\Orders\SoldOutException;
use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;

class OrderController extends Controller
{
    /** Público: o comprador não tem login (decisão de produto 1). */
    public function store(CreateOrderRequest $request, CreateOrder $action)
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || strlen($key) > 255) {
            return ApiError::make('idempotency_key_required', 'O header Idempotency-Key é obrigatório (até 255 caracteres).', 400);
        }

        try {
            ['order' => $order, 'replay' => $replay] = $action->execute($key, $request->validated());
        } catch (IdempotencyPayloadMismatchException) {
            return ApiError::make('idempotency_payload_mismatch', 'Esta Idempotency-Key já foi usada com dados diferentes.', 422);
        } catch (SoldOutException) {
            return ApiError::make('sold_out', 'Este lote está esgotado.', 409);
        } catch (BatchInactiveException) {
            return ApiError::make('batch_inactive', 'Este lote não está disponível para venda.', 409);
        } catch (ConcurrentIdempotencyKeyInFlightException) {
            return ApiError::make('service_unavailable', 'Tente novamente em instantes.', 503, retryAfter: 1);
        }

        $response = (new OrderResource($order->load('batch')))->response()->setStatusCode($replay ? 200 : 201);

        if ($replay) {
            $response->header('Idempotent-Replay', 'true');
        }

        return $response;
    }

    /** O UUID (não adivinhável) é o segredo de acesso; a resposta não expõe documento e mascara o e-mail. */
    public function show(Order $order)
    {
        return new OrderResource($order->load('batch'));
    }
}
