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
        $setting = SystemSetting::latest()->first();

        if (!$setting) {
            $setting = SystemSetting::create(['registration_locked' => false]);
        }

        if ($setting->registration_locked) {
            return response()->json([
                'success' => false,
                'message' =>  'التسجيل مغلق حاليًا '.$setting->lock_reason .'، يرجى المحاولة لاحقًا',
            ], 503);
        }

        return $next($request);
    }
}
