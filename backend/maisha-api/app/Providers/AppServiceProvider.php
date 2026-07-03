<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Login / register / Google OAuth
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many attempts. Please wait a moment and try again.',
                    ], 429);
                });
        });

        // Password reset – strictest, sends real emails
        RateLimiter::for('password', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many password reset attempts. Please wait before trying again.',
                    ], 429);
                });
        });

        // Public read‑only endpoints
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests. Please slow down.',
                    ], 429);
                });
        });

        // ─────────────────────────────────────────
        // WhatsApp webhook — global (all senders combined)
        // Catches distributed/botnet-style floods. Silent 200 on
        // breach, not 429 — Twilio's retry behavior on error responses
        // could otherwise turn a throttle into a retry storm. Breach
        // is logged so it's not invisible.
        // ─────────────────────────────────────────
        RateLimiter::for('whatsapp-global', function (Request $request) {
            return Limit::perMinute(60)->response(function (Request $request) {
                Log::warning('WhatsApp webhook throttled: global limit exceeded', [
                    'ip' => $request->ip(),
                ]);
                return response('', 200);
            });
        });

        // ─────────────────────────────────────────
        // WhatsApp webhook — per-sender (keyed on WaId)
        // Trustworthy now that signature validation runs before this
        // in the route middleware stack. Unlinked/unrecognized numbers
        // get a stricter cap since they can't reach the paid AI path
        // anyway. Silent 200 on breach, same reasoning as above.
        // ─────────────────────────────────────────
        RateLimiter::for('whatsapp-per-sender', function (Request $request) {
            $waId = $request->input('WaId', $request->ip());
            $isLinked = User::where('wa_number', $waId)->exists();

            $perMinute = $isLinked ? 5 : 2;
            $perHour   = $isLinked ? 30 : 10;
            

            $onBreach = function (Request $request) use ($waId, $isLinked) {
                Log::warning('WhatsApp webhook throttled: per-sender limit exceeded', [
                    'wa_id' => $waId,
                    'linked' => $isLinked,
                ]);
                return response('', 200);
            };

            return [
                Limit::perMinute($perMinute)->by($waId)->response($onBreach),
                Limit::perHour($perHour)->by($waId)->response($onBreach),
            ];
        });
    }
}