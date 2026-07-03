<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

class VerifyTwilioSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Twilio-Signature');

        if (! $signature) {
            Log::warning('Twilio webhook rejected: missing signature header', [
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        $authToken = config('services.twilio.auth_token');

        if (! $authToken) {
            Log::error('TWILIO_AUTH_TOKEN not configured — cannot validate webhook signature');

            return response('Server misconfiguration', 500);
        }

        $validator = new RequestValidator($authToken);

        // fullUrl() must resolve to the exact public URL Twilio POSTed to.
        // Relies on trustProxies(at: '*') in bootstrap/app.php to correctly
        // reconstruct https://<ngrok-host>/... instead of localhost.
        $url = $request->fullUrl();
        $params = $request->request->all(); // POST body params only, as Twilio signs them

        $isValid = $validator->validate($signature, $url, $params);

        if (! $isValid) {
            Log::warning('Twilio webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'url_checked' => $url,
            ]);

            return response('Forbidden', 403);
        }

        return $next($request);
    }
}