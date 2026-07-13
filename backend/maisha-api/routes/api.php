<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ExpenseLogController;
use App\Http\Controllers\UtakulaaController;

// Dashboard Profile Controllers
use App\Http\Controllers\HealthProfileController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\MealPatternController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\ActivityProfileController;
use App\Http\Controllers\ProfileCompletionController;

// Medication Alerts
use App\Http\Controllers\MedicationAlertController;

// Meal Selection
use App\Http\Controllers\MealSelectionController;

// WhatsApp Webhook
use App\Http\Controllers\WhatsAppWebhookController;

// ─────────────────────────────────────────────
// Auth routes — register & OAuth — 10 req/min per IP
// ─────────────────────────────────────────────
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register',            [AuthController::class, 'register']);
    Route::get('/auth/google',          [AuthController::class, 'googleRedirect']);
    Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);
});

// ─────────────────────────────────────────────
// Login route — 5 req/min per email+IP
// ─────────────────────────────────────────────
Route::middleware('throttle:login-attempts')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ─────────────────────────────────────────────
// Password reset — 3 req/min per IP
// ─────────────────────────────────────────────
Route::middleware('throttle:password')->group(function () {
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AuthController::class, 'resetPassword'])
        ->name('password.reset');
});

// ─────────────────────────────────────────────
// Public routes
// ─────────────────────────────────────────────
Route::middleware('throttle:public')->group(function () {
    Route::get('/ingredients', [IngredientController::class, 'index']);

    Route::get('/ping', function () {
        return response()->json([
            'status' => 'ok',
        ]);
    });
});

// ─────────────────────────────────────────────
// WhatsApp Webhook (public)
// GET /webhook = Meta hub-challenge verify (dormant, Meta permanently
//   blocked — see PROJECT_STATE.md). No signature check needed/possible
//   here since Meta's verify flow doesn't send a Twilio signature.
// POST /webhook = actual inbound Twilio messages — signature-verified.
// ─────────────────────────────────────────────
// Middleware order matters:
//   1. whatsapp-global throttle — cheapest check, catches raw volume
//      floods before anything else runs
//   2. twilio.signature — rejects forged requests
//   3. whatsapp-per-sender throttle — keyed on WaId, only trustworthy
//      after signature validation has run
// ─────────────────────────────────────────────
Route::prefix('whatsapp')->group(function () {
    Route::get('/webhook', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/webhook', [WhatsAppWebhookController::class, 'handle'])
        ->middleware(['throttle:whatsapp-global', 'twilio.signature', 'throttle:whatsapp-per-sender']);
});

// ─────────────────────────────────────────────
// Sanctum fallback
// ─────────────────────────────────────────────
Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated.',
    ], 401);
})->name('login');

// ─────────────────────────────────────────────
// Protected Routes (Bearer token required)
// ─────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'api.logging'])->group(function () {

    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return response()->json([
            'user'      => $request->user(),
            'onboarded' => (bool) $request->user()->onboarded,
        ]);
    });

    // ─────────────────────────────────────────
    // Onboarding
    // ─────────────────────────────────────────
    Route::get('/onboarding/status',      [OnboardingController::class, 'status']);
    Route::post('/onboarding/step-about', [OnboardingController::class, 'stepAbout']);
    Route::post('/onboarding/step-1',     [OnboardingController::class, 'step1']);
    Route::post('/onboarding/step-2',     [OnboardingController::class, 'step2']);
    Route::post('/onboarding/step-3',     [OnboardingController::class, 'step3']);
    Route::post('/onboarding/complete',   [OnboardingController::class, 'complete']);

    // New progress endpoint
    Route::get('/onboarding/progress',    [OnboardingController::class, 'progress']);

    // ─────────────────────────────────────────
    // Dashboard panel setup state
    // ─────────────────────────────────────────
    Route::get('/dashboard/setup-state', [\App\Http\Controllers\PanelSetupController::class, 'setupState']);

    // ─────────────────────────────────────────
    // Budget & Expenses
    // ─────────────────────────────────────────
    Route::get('/budget/today',  [BudgetController::class, 'today']);
    Route::get('/budget/weekly', [BudgetController::class, 'weekly']);
    Route::post('/expense-logs', [ExpenseLogController::class, 'store']);

    // ─────────────────────────────────────────
    // Health Profile
    // ─────────────────────────────────────────
    Route::post('/profile/health', [HealthProfileController::class, 'update']);
    Route::get('/profile/health',  [HealthProfileController::class, 'show']);

    // ─────────────────────────────────────────
    // Goals
    // ─────────────────────────────────────────
    Route::post('/profile/goals', [GoalController::class, 'update']);
    Route::get('/profile/goals',  [GoalController::class, 'show']);

    // ─────────────────────────────────────────
    // Meal Pattern
    // ─────────────────────────────────────────
    Route::post('/profile/meal-pattern', [MealPatternController::class, 'update']);
    Route::get('/profile/meal-pattern',  [MealPatternController::class, 'show']);

    // ─────────────────────────────────────────
    // Medications
    // ─────────────────────────────────────────
    Route::post('/profile/medications', [MedicationController::class, 'store']);
    Route::get('/profile/medications',  [MedicationController::class, 'list']);
    // New PUT route for updating a medication
    Route::put('/profile/medications/{id}', [MedicationController::class, 'update']);
    Route::delete('/profile/medications/{id}', [MedicationController::class, 'destroy']);

    // ─────────────────────────────────────────
    // Pantry (Phase 2 REST-style routes)
    // Updated to use /profile/pantry prefix for consistency
    // ─────────────────────────────────────────
    Route::post('/profile/pantry', [PantryController::class, 'update']);
    Route::get('/profile/pantry',  [PantryController::class, 'list']);
    Route::delete('/profile/pantry/{ingredientId}', [PantryController::class, 'destroy']);

    // ─────────────────────────────────────────
    // Activity Profile
    // ─────────────────────────────────────────
    Route::post('/profile/activity', [ActivityProfileController::class, 'update']);
    Route::get('/profile/activity',  [ActivityProfileController::class, 'show']);

    // ─────────────────────────────────────────
    // Profile Completion
    // ─────────────────────────────────────────
    Route::get('/profile/completion', [ProfileCompletionController::class, 'completion']);

    // ─────────────────────────────────────────
    // Medication Alerts
    // ─────────────────────────────────────────
    Route::get('/medication-alerts/due', [MedicationAlertController::class, 'due']);

    // ─────────────────────────────────────────
    // Meal Selection Tracking
    // ─────────────────────────────────────────
    Route::post('/meal-selection', [MealSelectionController::class, 'store']);

    // ─────────────────────────────────────────
    // Utakulaa AI
    // ─────────────────────────────────────────
    Route::post('/utakulaa', [UtakulaaController::class, 'store']);
    Route::get('/meal-suggestions', [UtakulaaController::class, 'index']);
});
