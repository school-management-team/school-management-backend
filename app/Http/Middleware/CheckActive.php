<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckActive
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً'
            ], 401);
        }

        if (!$request->user()->is_active) {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => false,
                'message' => 'حسابك غير مفعل، يرجى التواصل مع الإدارة'
            ], 403);
        }

        return $next($request);
    }
}
