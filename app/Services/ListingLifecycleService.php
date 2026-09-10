<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ListingLifecycleService
{
    /** @param array<string, mixed> $values */
    public function create(User $user, array $values): Listing
    {
        return DB::transaction(function () use ($user, $values): Listing {
            if (! in_array($values['status'], ['draft', 'ready', 'active'], true)) {
                throw new RuntimeException('新規の出品データは下書き、準備完了、出品中で作成してください。');
            }
            if ($values['status'] === 'active') {
                $values['listed_at'] = now();
            }

            return $user->listings()->create($values);
        });
    }

    /** @param array<string, mixed> $values */
    public function update(User $user, Listing $listing, array $values): Listing
    {
        return DB::transaction(function () use ($user, $listing, $values): Listing {
            $lockedListing = Listing::query()->whereKey($listing->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedListing->status, ['sold', 'ended', 'cancelled'], true)) {
                throw new RuntimeException('終了済みの出品データは変更できません。');
            }
            if (! in_array($values['status'], Listing::USER_SETTABLE_STATUSES, true)) {
                throw new RuntimeException('売却済みは販売記録からのみ更新できます。');
            }
            if ($values['status'] !== $lockedListing->status && ! $this->canTransition($lockedListing->status, $values['status'])) {
                throw new RuntimeException('この出品状態から選択した状態へは変更できません。');
            }
            if ($values['status'] === 'active' && $lockedListing->listed_at === null) {
                $values['listed_at'] = now();
            }
            if (in_array($values['status'], ['ended', 'cancelled'], true)) {
                $values['ended_at'] = now();
            }

            $lockedListing->update($values);

            return $lockedListing;
        });
    }

    private function canTransition(string $from, string $to): bool
    {
        return in_array($to, [
            ...match ($from) {
                'draft' => ['ready', 'active', 'cancelled'],
                'ready' => ['draft', 'active', 'cancelled'],
                'active' => ['ended', 'cancelled'],
                default => [],
            },
            $from,
        ], true);
    }
}
