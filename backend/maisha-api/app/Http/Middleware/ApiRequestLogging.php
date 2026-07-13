<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ApiRequestLogging
{
    /**
     * Handle an incoming request.
     *
     * Adds request ID for tracing, logs request metadata, and adds security headers.
     * Does NOT restructure response bodies — only adds headers and logging.
     */
    public function handle(Request $request, Closure $next)
    {
        // Generate or retrieve request ID
        $requestId = Str::uuid()->toString();
        $request->attributes->set('request_id', $requestId);

        // Log incoming request to api_audit channel
        Log::channel('api_audit')->info('API request', [
            'request_id' => $requestId,
            'method'     => $request->method(),
            'path'       => $request->path(),
            'user_id'    => $request->user()?->id,
            'ip'         => $request->ip(),
        ]);

        // Process the request
        $response = $next($request);

        // Add request ID and security headers to response
        $response->header('X-Request-ID', $requestId);
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('X-XSS-Protection', '1; mode=block');

        // Log response to api_audit channel
        Log::channel('api_audit')->info('API response', [
            'request_id' => $requestId,
            'status'     => $response->status(),
            'user_id'    => $request->user()?->id,
        ]);

        return $response;
    }
}
