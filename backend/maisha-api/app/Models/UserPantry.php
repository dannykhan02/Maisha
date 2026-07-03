<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPantry extends Model
{
    protected $table = 'user_pantry';
    protected $fillable = ['user_id', 'ingredient_id', 'tier', 'quantity', 'unit', 'restocked_at', 'is_depleted'];
    protected $casts = ['restocked_at' => 'datetime', 'is_depleted' => 'boolean'];

    public function user() { return $this->belongsTo(User::class); }
    public function ingredient() { return $this->belongsTo(Ingredient::class); }
}