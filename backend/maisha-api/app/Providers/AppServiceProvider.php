<?php

namespace App\Providers;

use App\Models\User;
use App\Models\WhatsappConversationState;
use App\Models\WhatsappSession;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many attempts. Please wait a moment and try again.',
                    ], 429);
                });
        });

        RateLimiter::for('login-attempts', function (Request $request) {
            $email = $request->input('email', '');
            $key = $email . '|' . $request->ip();
            return Limit::perMinute(5)
                ->by($key)
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many login attempts. Please wait a moment and try again.',
                    ], 429);
                });
        });

        RateLimiter::for('password', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many password reset attempts. Please wait before trying again.',
                    ], 429);
                });
        });

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
        //
        // "Linked" is determined via WhatsappSession, not User::wa_number.
        // Per Pass 0 investigation (2026-07-15): users.wa_number is never
        // written anywhere in the codebase, and WhatsappSession.user_id was
        // also 0 across every row prior to this fix — the entire linking
        // mechanism (ProcessIncomingWhatsAppMessage) had never actually
        // fired, because its dispatch call was left commented out in
        // WhatsAppWebhookController. That dispatch has now been wired up
        // (see controller), so sessions should begin linking going forward.
        // This limiter now reads from the table that mechanism actually
        // writes to.
        //
        // Guided-flow exception unchanged: a sender mid-way through a
        // guided, non-AI data capture flow (BP/sugar) isn't touching the
        // paid AI path, so it gets a relaxed 4/min instead of the full
        // 2/min AI-cost-containment cap.
        // ─────────────────────────────────────────
        RateLimiter::for('whatsapp-per-sender', function (Request $request) {
            $waId = $request->input('WaId', $request->ip());

            $isLinked = WhatsappSession::where('wa_number', $waId)
                ->whereNotNull('user_id')
                ->exists();

            $hasActiveGuidedFlow = WhatsappConversationState::whereHas('user', function ($q) use ($waId) {
                    $q->where('wa_number', $waId)->orWhere('phone', $waId);
                })
                ->whereIn('flow', ['bp_capture', 'sugar_capture'])
                ->exists();

            $perMinute = $isLinked ? 5 : ($hasActiveGuidedFlow ? 4 : 2);
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