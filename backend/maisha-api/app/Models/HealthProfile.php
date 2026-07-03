<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthProfile extends Model
{
    protected $fillable = [
        'user_id', 'conditions', 'allergies', 'sensitivities',
        'fitness_goal', 'medical_notes', 'health_confirmed',
        // New fields for unmapped condition tracking
        'mapped_condition_tags',
        'has_unmapped_condition',
        'condition_classification_status',
    ];

    // ── DEPRECATED FIELDS (Phase 1 Consolidation) ──────────────────────────
    // The following field is no longer read by core logic and is kept only
    // for backward compatibility:
    //
    // - medications → DEPRECATED: use UserMedication table instead
    //   UtakulaaService reads from UserMedication::where('user_id', $user->id)
    //   which provides enriched data (dosage, frequency, food_condition, etc.)
    //
    // This field will be removed in a future major version.
    // ──────────────────────────────────────────────────────────────────────

    protected $casts = [
        'conditions'       => 'array',
        'allergies'        => 'array',
        'sensitivities'    => 'array',
        'health_confirmed' => 'boolean',
        // New casts
        'mapped_condition_tags'  => 'array',
        'has_unmapped_condition' => 'boolean',
        // condition_classification_status is an enum string, no cast needed
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}