<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login só para administradores (e-mail + senha) com token Bearer do Sanctum.
 * Não há cadastro público: compradores não têm conta.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais informadas estão incorretas.'],
            ]);
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->createToken('auth_token')->plainTextToken,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Logout realizado com sucesso.']]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }
}
