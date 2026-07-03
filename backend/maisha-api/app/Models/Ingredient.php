<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = [
        'name', 'name_sw', 'category', 'price_kes', 'price_unit',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g',
        'sodium_mg', 'potassium_mg', 'iron_mg', 'vitamin_c_mg', 'glycaemic_index',
        'condition_flags', 'allergen_flags', 'peak_months',
        'available', 'in_season',
    ];

    protected $casts = [
        'condition_flags' => 'array',
        'allergen_flags'  => 'array',
        'peak_months'      => 'array',
        'available'       => 'boolean',
        'in_season'       => 'boolean',
        'price_kes'       => 'decimal:2',
        'calories'        => 'decimal:2',
        'protein_g'       => 'decimal:2',
        'carbs_g'         => 'decimal:2',
        'fat_g'           => 'decimal:2',
        'fibre_g'         => 'decimal:2',
        'sodium_mg'       => 'decimal:2',
        'potassium_mg'    => 'decimal:2',
        'iron_mg'         => 'decimal:2',
        'vitamin_c_mg'    => 'decimal:2',
    ];
}