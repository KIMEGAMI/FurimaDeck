<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionItemImage extends Model
{
    protected $fillable = [
        'path',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function auctionItem(): BelongsTo
    {
        return $this->belongsTo(AuctionItem::class);
    }
}
