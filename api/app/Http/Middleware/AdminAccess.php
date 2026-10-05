<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deve rodar DEPOIS de `auth:sanctum`: sem token => 401 (Authenticate);
 * autenticado mas não admin => 403 (aqui).
 */
class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiError::make('unauthenticated', 'Não autenticado.', 401);
        }

        if (! $user->isAdmin()) {
            return ApiError::make('forbidden', 'Acesso restrito a administradores.', 403);
        }

        return $next($request);
    }
}
