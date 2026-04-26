<?php
// app/Http/Middleware/CheckForceLogout.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckForceLogout
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if ($user && $user->checkForceLogout()) {
            $user->currentAccessToken()->delete();
            
            return response()->json([
                'success' => false,
                'message' => 'تم تغيير كلمة المرور. يرجى تسجيل الدخول مرة أخرى.',
                'code' => 'force_logout'
            ], 401);
        }

        return $next($request);
    }
}