<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Marketplace extends Model
{
    public const FEE_TYPES = [
        'percentage',
        'fixed',
        'mixed',
    ];

    protected $fillable = [
        'code',
        'name',
        'base_url',
        'listing_url',
        'fee_type',
        'default_fee_rate',
        'rule_config_json',
        'rule_source_url',
        'rule_last_reviewed_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_fee_rate' => 'decimal:2',
            'rule_config_json' => 'array',
            'rule_last_reviewed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
