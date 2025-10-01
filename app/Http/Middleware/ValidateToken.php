<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateToken
{
    /**
     * Handle an incoming request.
     * Check Authorization bearer token against Redis key `user_session:{token}`.
     * If valid, decode JSON payload and attach to request attribute 'auth_user'.
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow OPTIONS requests for CORS preflight
        if ($request->isMethod('options')) {
            return $next($request);
        }

        $authHeader = $request->header('Authorization') ?? $request->bearerToken();
        $token = null;

        if ($authHeader && is_string($authHeader)) {
            if (stripos($authHeader, 'bearer ') === 0) {
                $token = trim(substr($authHeader, 7));
            } else {
                $token = $authHeader;
            }
        }

        if (empty($token)) {
            return response()->json(['success' => false, 'message' => 'No token provided', 'data' => null], 401);
        }

        $key = 'user_session:' . $token;
        try {
            $predis = new \Predis\Client([
                'scheme' => env('REDIS_SCHEME', 'tcp'),
                'host'   => env('REDIS_HOST', '127.0.0.1'),
                'port'   => env('REDIS_PORT', 6379),
                'password' => env('REDIS_PASSWORD', null),
                'database' => env('REDIS_DB', 0),
            ]);
            $payload = $predis->get($key);
        } catch (\Throwable $e) {
            $payload = null;
        }

        if (empty($payload)) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid or expired token)', 'data' => null], 401);
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated (invalid token payload)', 'data' => null], 401);
        }

        // attach to request for controllers
        $request->attributes->set('auth_user', $data);

        return $next($request);
    }
}
