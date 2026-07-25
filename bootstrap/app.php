<?php

use App\Http\Middleware\CheckActive;
use App\Http\Middleware\CheckForceLogout;
use App\Http\Middleware\CheckRegistrationOpen;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;



return Application::configure(basePath: dirname(__DIR__))
     ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'=>CheckRole::class,
            'active'=>CheckActive::class,
            'registration.open' =>CheckRegistrationOpen::class]);
        $middleware->api([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();



