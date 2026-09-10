<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    public const TYPES = [
        'wholesaler',
        'store',
        'online_shop',
        'flea_market',
        'auction',
        'individual',
        'antique_market',
        'other',
    ];

    protected $fillable = [
        'name',
        'type',
        'memo',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
