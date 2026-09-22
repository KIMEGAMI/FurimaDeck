<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    public const TYPE_LABELS = [
        'wholesaler' => '卸売業者',
        'store' => '実店舗',
        'online_shop' => 'ネットショップ',
        'flea_market' => 'フリーマーケット',
        'auction' => 'オークション',
        'individual' => '個人',
        'antique_market' => '古物市場',
        'other' => 'その他',
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

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPE_LABELS);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? '不明';
    }
}
