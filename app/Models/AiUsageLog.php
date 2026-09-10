<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'provider', 'model', 'purpose', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'estimated_cost_jpy', 'input_hash', 'success', 'error_code'];

    protected function casts(): array
    {
        return ['estimated_cost_usd' => 'decimal:6', 'estimated_cost_jpy' => 'decimal:4', 'success' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
