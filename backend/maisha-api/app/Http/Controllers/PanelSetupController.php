<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PanelSetupController extends Controller
{
    public function setupState(Request $request)
    {
        $user    = $request->user();
        $profile = $user->healthProfile;

        $classificationStatus = $profile?->condition_classification_status ?? 'none';
        $classificationPending = $classificationStatus === 'pending';

        // Diet ready: budget set AND health confirmed
        // If classification is still pending, diet is NOT ready — 
        // engine might get wrong context
        $dietReady = !is_null($user->daily_budget_kes)
            && (bool) ($profile?->health_confirmed ?? false)
            && !$classificationPending;

        // Budget ready: budget AND income pattern both set
        $budgetReady = !is_null($user->daily_budget_kes)
            && !is_null($user->income_pattern);

        // Medicine ready the moment onboarding is done —
        // zero medications is valid "ready" state
        $medicineReady  = (bool) $user->onboarded;
        $medicationCount = $user->medications()->active()->count();

        return response()->json([
            'panels' => [
                'diet' => [
                    'ready'                  => $dietReady,
                    'classification_pending' => $classificationPending,
                    'reason'                 => !$dietReady
                        ? ($classificationPending
                            ? 'processing_health_info'
                            : 'budget_or_health_incomplete')
                        : null,
                ],
                'medicine' => [
                    'ready'            => $medicineReady,
                    'medication_count' => $medicationCount,
                ],
                'budget' => [
                    'ready'  => $budgetReady,
                    'reason' => !$budgetReady
                        ? 'budget_or_income_pattern_missing'
                        : null,
                ],
            ],
        ]);
    }
}