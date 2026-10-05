<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * Formato de erro padronizado em TODAS as respostas de erro da API (ver
 * README raiz, seção "Contrato"). `fields` e `retry_after` só aparecem
 * quando fazem sentido para o código do erro.
 */
class ApiError
{
    public static function make(string $code, string $message, int $status, ?array $fields = null, ?int $retryAfter = null): JsonResponse
    {
        $body = ['error' => ['code' => $code, 'message' => $message]];

        if ($fields !== null) {
            $body['error']['fields'] = $fields;
        }
        if ($retryAfter !== null) {
            $body['error']['retry_after'] = $retryAfter;
        }

        $response = response()->json($body, $status);

        if ($retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
