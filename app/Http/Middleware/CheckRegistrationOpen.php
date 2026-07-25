<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRegistrationOpen
{

    public function handle(Request $request, Closure $next): Response
    {
        $setting = SystemSetting::current();

        if ($setting->registration_locked) {
            return response()->json([
                'success' => false,
                'message' => $setting->lock_reason ?: 'التسجيل مغلق حاليًا، يرجى المحاولة لاحقًا',
            ], 503);
        }

        return $next($request);
    }
}
