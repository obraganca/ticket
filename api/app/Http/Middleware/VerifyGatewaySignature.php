<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;

/**
 * Verifica HMAC-SHA256 do corpo BRUTO (não do array já parseado, para não
 * depender de serialização estável). Segredo compartilhado com os comandos
 * `gateway:*` que simulam o provedor externo.
 */
class VerifyGatewaySignature
{
    public function handle(Request $request, Closure $next)
    {
        $signature = $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), config('services.gateway.secret'));

        if (! hash_equals($expected, $signature)) {
            return ApiError::make('invalid_signature', 'Assinatura inválida.', 401);
        }

        return $next($request);
    }
}
