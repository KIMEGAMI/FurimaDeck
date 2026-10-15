<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionMessageTemplate extends Model
{
    public const PURPOSES = ['greeting', 'size_answer', 'shipping', 'custom'];

    protected $fillable = ['user_id', 'name', 'purpose', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
