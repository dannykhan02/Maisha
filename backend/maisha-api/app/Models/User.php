<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Messages\MailMessage;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
        'phone', 'wa_number', 'institution', 'role',
        'google_id', 'avatar', 'auth_provider', 'email_verified_at',
        'daily_budget_kes', 'budget_strictness',
        'primary_goals',
        'secondary_goal', 'goal_timeline',
        'cooks_at_home', 'meals_per_day',
        'foods_loved', 'foods_avoided', 'cuisine_preference',
        'weight_kg', 'activity_level', 'exercise_frequency',
        'glass_size_ml', 'hydration_reminders',
        'whatsapp_nudge_types', 'nudge_time_preference',
        'onboarded',
        'goals',
        'age', 'height_cm', 'blood_type', 'bmi',
        'onboarding_step',
        'income_pattern',
        'budget_range',
        'budget_is_custom',
    ];

    // ── DEPRECATED FIELDS (Phase 1 Consolidation) ──────────────────────────
    // The following fields are no longer read by core logic and are kept only
    // for backward compatibility. Use the recommended replacements instead:
    //
    // - activity_level → DEPRECATED: use UserActivityProfile.activity_level
    // - meals_per_day → DEPRECATED: use UserMealPattern.meals_per_day
    //
    // These fields will be removed in a future major version.
    // ──────────────────────────────────────────────────────────────────────

    protected $hidden = [
        'password', 'remember_token', 'google_id',
    ];

    protected $casts = [
        'email_verified_at'     => 'datetime',
        'password'              => 'hashed',
        'foods_loved'           => 'array',
        'foods_avoided'         => 'array',
        'whatsapp_nudge_types'  => 'array',
        'cooks_at_home'         => 'boolean',
        'hydration_reminders'   => 'boolean',
        'onboarded'             => 'boolean',
        'daily_budget_kes'      => 'decimal:2',
        'weight_kg'             => 'decimal:2',
        'meals_per_day'         => 'integer',
        'glass_size_ml'         => 'integer',
        'goals'                 => 'array',
        'primary_goals'         => 'array',
        'age'                   => 'integer',
        'height_cm'             => 'decimal:1',
        'blood_type'            => 'string',
        'bmi'                   => 'decimal:1',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->healthProfile()->create([
                'fitness_goal' => 'maintain',
            ]);
        });
    }

    // Relationships
    public function healthProfile()
    {
        return $this->hasOne(HealthProfile::class);
    }

    public function mealSuggestions() { return $this->hasMany(MealSuggestion::class); }
    public function budgetLogs()      { return $this->hasMany(BudgetLog::class); }
    public function expenseLogs()     { return $this->hasMany(ExpenseLog::class); }
    public function userHabits()      { return $this->hasMany(UserHabit::class); }
    public function waterDailySummaries() { return $this->hasMany(WaterDailySummary::class); }
    public function medications() { return $this->hasMany(\App\Models\UserMedication::class); }
    public function whatsappSession() { return $this->hasOne(WhatsappSession::class); }

    public function sendPasswordResetNotification($token)
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        $resetUrl = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($this->email);

        $this->notify(new class($resetUrl, $this->name, $token) extends \Illuminate\Auth\Notifications\ResetPassword {
            protected $resetUrl;
            protected $name;

            public function __construct($resetUrl, $name, $token)
            {
                parent::__construct($token);
                $this->resetUrl = $resetUrl;
                $this->name = $name;
            }

            public function toMail($notifiable)
            {
                return (new MailMessage)
                    ->subject('Reset your Maisha password')
                    ->view('auth.mail.reset-password', [
                        'name' => $this->name,
                        'resetUrl' => $this->resetUrl,
                    ]);
            }
        });
    }
}