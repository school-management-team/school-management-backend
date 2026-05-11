<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'يجب تسجيل الدخول أولاً'
            ], 401);
        }

        if (!$request->user()->isVerified()) {
            return response()->json([
                'success' => false,
                'message' => 'يرجى تفعيل بريدك الإلكتروني أولاً',
                'code' => 'email_not_verified'
            ], 403);
        }

        return $next($request);
    }
}
