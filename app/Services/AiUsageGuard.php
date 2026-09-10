<?php

namespace App\Services;

use App\Models\User;

class AiUsageGuard
{
    public function canRequest(User $user, int $estimatedCostJpy): bool
    {
        if ($estimatedCostJpy < 0) {
            return false;
        }

        $used = (float) $user->aiUsageLogs()->where('created_at', '>=', now()->startOfMonth())->sum('estimated_cost_jpy');

        return $used + $estimatedCostJpy <= (float) config('furimadeck.ai.monthly_cost_limit_jpy');
    }
}
