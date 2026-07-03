<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPriceReport extends Model
{
    protected $fillable = [
        'ingredient_id', 'reported_by', 'price_kes', 'location', 'verified',
    ];

    protected $casts = [
        'price_kes' => 'decimal:2',
        'verified'  => 'boolean',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}