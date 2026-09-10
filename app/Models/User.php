<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'google_id',
    'subscription_plan',
    'subscription_status',
    'stripe_customer_id',
    'stripe_subscription_id',
    'premium_started_at',
    'premium_ends_at',
    'trial_used_at',
    'stripe_subscription_event_created_at',
    'last_login_at',
    'last_login_ip',
    'last_login_user_agent_hash',
    'suspicious_login_detected_at',
    'is_admin',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const SUBSCRIPTION_ACTIVE = 'active';

    public const SUBSCRIPTION_INACTIVE = 'inactive';

    public const LEGACY_SUBSCRIPTION_PREMIUM = 'premium';

    public const PLAN_FREE = self::SUBSCRIPTION_INACTIVE;

    public const PLAN_PREMIUM = self::SUBSCRIPTION_ACTIVE;

    public const FREE_AUCTION_ITEM_LIMIT = 50;

    public const FREE_CATEGORY_LIMIT = 5;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'premium_started_at' => 'datetime',
            'premium_ends_at' => 'datetime',
            'trial_used_at' => 'datetime',
            'stripe_subscription_event_created_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspicious_login_detected_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->isAdmin() || $this->isDemoUser()) {
            return true;
        }

        if (! in_array($this->subscription_plan, [self::SUBSCRIPTION_ACTIVE, self::LEGACY_SUBSCRIPTION_PREMIUM], true)) {
            return false;
        }

        if (in_array($this->subscription_status, ['active', 'trialing'], true)) {
            return true;
        }

        return $this->premium_ends_at !== null && $this->premium_ends_at->isFuture();
    }

    public function isPremium(): bool
    {
        return $this->hasActiveSubscription();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isDemoUser(): bool
    {
        $demoEmail = config('demo.user_email');

        return is_string($demoEmail) && $demoEmail !== '' && $this->email === $demoEmail;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->isDemoUser() || parent::hasVerifiedEmail();
    }

    /** @return HasMany<AuctionItem, User> */
    public function auctionItems(): HasMany
    {
        return $this->hasMany(AuctionItem::class);
    }

    /** @return HasMany<Product, User> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Supplier, User> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<Listing, User> */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** @return HasMany<Sale, User> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<AiUsageLog, User> */
    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    /** @return HasMany<AuditLog, User> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
