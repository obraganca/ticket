<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O UUID do pedido é o "segredo" de posse (o comprador recebe o link por
 * e-mail/redirect). Por isso NUNCA expomos o documento aqui, e mascaramos o
 * e-mail — qualquer um com o link só vê o essencial para acompanhar a compra.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'batch_id' => $this->batch_id,
            'batch_name' => $this->batch->name,
            'quantity' => $this->quantity,
            'total_cents' => $this->total_cents,
            'buyer_email' => $this->maskEmail($this->buyer_email),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email) + [1 => ''];
        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
