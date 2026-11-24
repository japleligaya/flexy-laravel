<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Payroll\AgentTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use Symfony\Component\HttpFoundation\Response;

final class AgentAuthController
{
    public function login(Request $request, AgentTokenService $tokens, Timebox $timebox): JsonResponse
    {
        $credentials = $this->body($request, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $provider = Auth::createUserProvider(config('auth.guards.web.provider'));
        $user = $timebox->call(function (Timebox $timebox) use ($provider, $credentials) {
            $user = $provider->retrieveByCredentials($credentials);
            if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
                return null;
            }

            $timebox->returnEarly();

            return $user;
        }, 200000);
        abort_if($user === null, 401);

        return response()->json(['user' => $this->identity($user), 'tokens' => $tokens->issue($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        abort_unless($request->query() === [], 422);

        return response()->json($this->identity($request->user()));
    }

    public function refresh(Request $request, AgentTokenService $tokens): JsonResponse
    {
        return response()->json(['tokens' => $tokens->rotate($this->refreshToken($request))]);
    }

    public function revoke(Request $request, AgentTokenService $tokens): Response
    {
        $tokens->revoke($this->refreshToken($request));

        return response()->noContent();
    }

    private function body(Request $request, array $rules): array
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $validation = ['body' => 'array:'.implode(',', array_keys($rules))];
        foreach ($rules as $key => $rule) {
            $validation['body.'.$key] = $rule;
        }

        return Validator::make(['body' => $request->json()->all()], $validation)->validate()['body'];
    }

    private function refreshToken(Request $request): string
    {
        return $this->body($request, [
            'refresh_token' => ['required', 'string', 'regex:'.AgentTokenService::TOKEN_PATTERN],
        ])['refresh_token'];
    }

    private function identity(User $user): array
    {
        return ['id' => (string) $user->getKey(), 'email' => $user->email];
    }
}
