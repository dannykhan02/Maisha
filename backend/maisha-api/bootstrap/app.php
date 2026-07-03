<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Trust all proxies for forwarded headers (X-Forwarded-Proto, X-Forwarded-Host).
        // Needed so $request->fullUrl() resolves to the public ngrok HTTPS URL
        // (as Twilio saw it) rather than localhost:8000 — required for Twilio
        // signature validation to work correctly behind ngrok.
        // TODO before production: replace '*' with the actual load balancer /
        // reverse proxy IP range once deployed, rather than trusting all proxies.
        $middleware->trustProxies(at: '*');

        // Register named middleware for API request logging
        $middleware->alias([
            'api.logging' => \App\Http\Middleware\ApiRequestLogging::class,
            'twilio.signature' => \App\Http\Middleware\VerifyTwilioSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Return JSON 401 instead of redirect for API unauthenticated requests
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();