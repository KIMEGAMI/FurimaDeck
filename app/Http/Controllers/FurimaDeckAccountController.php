<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Models\User;
use App\Services\EmailVerificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class FurimaDeckAccountController extends Controller
{
    private const STRIPE_SUBSCRIPTION_LIST_LIMIT = 100;

    public function edit(Request $request): View
    {
        return view('furimadeck_account.edit', [
            'user' => $request->user(),
            'salesTotal' => Schema::hasTable('sales')
                ? (int) $request->user()->sales()
                    ->whereIn('status', Sale::VALID_SOLD_STATUSES)
                    ->sum('sold_price')
                : 0,
        ]);
    }

    public function update(ProfileUpdateRequest $request, EmailVerificationDelivery $emailVerificationDelivery): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        $response = redirect()->route('furimadeck-account.edit')->with('status', 'profile-updated');
        if ($emailChanged) {
            $response->with('verification_status', $emailVerificationDelivery->send($user, 'furimadeck_account_email_changed')
                ? EmailVerificationDelivery::sentStatus()
                : EmailVerificationDelivery::failureStatus());
        }

        return $response;
    }

    public function confirmDataDeletion(Request $request): View
    {
        $user = $request->user();

        return view('furimadeck_account.confirm-data-deletion', ['user' => $user]);
    }

    public function confirmMonthlyDataDeletion(Request $request): View
    {
        $user = $request->user();

        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        return view('furimadeck_account.confirm-monthly-data-deletion', [
            'user' => $user,
            'month' => $validated['month'],
        ]);
    }

    public function destroyMonthlyData(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validateWithBag('monthlyDataDeletion', [
            'month' => ['required', 'date_format:Y-m'],
            'password' => ['required', 'current_password'],
            'confirm_monthly_data_deletion' => ['accepted'],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month']);
        $start = $month->startOfMonth();
        $end = $start->addMonth();
        $saleIds = DB::table('sales')
            ->where('user_id', $user->id)
            ->where('sold_at', '>=', $start)
            ->where('sold_at', '<', $end)
            ->pluck('id');

        DB::transaction(function () use ($user, $saleIds, $start, $end): void {
            if ($saleIds->isNotEmpty()) {
                DB::table('accounting_entries')
                    ->where('user_id', $user->id)
                    ->whereIn('sale_id', $saleIds)
                    ->delete();
                DB::table('sales')
                    ->where('user_id', $user->id)
                    ->whereIn('id', $saleIds)
                    ->delete();
            }
            if (Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')
                    ->where('user_id', $user->id)
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->delete();
            }
        });

        return redirect()->route('furimadeck-account.edit')->with('status', 'monthly-data-deleted');
    }

    public function destroyData(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validateWithBag('dataDeletion', [
            'password' => ['required', 'current_password'],
            'confirm_data_deletion' => ['accepted'],
        ]);

        $productImages = $this->productImages($user->id);
        $auctionImages = $this->auctionImages($user->id);
        $importBatchIds = DB::table('import_batches')->where('user_id', $user->id)->pluck('id');

        DB::transaction(function () use ($user, $importBatchIds): void {
            $this->deleteRowsForUser('accounting_entries', $user->id);
            $this->deleteRowsForUser('accounting_connections', $user->id);
            $this->deleteRowsForUser('ai_usage_logs', $user->id);
            $this->deleteRowsForUser('ai_response_caches', $user->id);
            $this->deleteRowsForUser('audit_logs', $user->id);
            $this->deleteRowsForUser('subscription_cancellation_feedback', $user->id);
            $this->deleteRowsForUser('sales', $user->id);
            $this->deleteRowsForUser('listings', $user->id);
            $this->deleteRowsForUser('auction_items', $user->id);
            if ($importBatchIds->isNotEmpty()) {
                DB::table('import_row_results')->whereIn('import_batch_id', $importBatchIds)->delete();
                DB::table('import_batches')->whereIn('id', $importBatchIds)->delete();
            }
            $this->deleteRowsForUser('products', $user->id);
            $this->deleteRowsForUser('suppliers', $user->id);
        });

        $this->deleteProductImages($productImages, $user->id);
        $this->deleteAuctionImages($auctionImages);

        return redirect()->route('furimadeck-account.edit')->with('status', 'data-deleted');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isAdmin() || $user->isDemoUser(), 403);
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
            'confirm_deletion' => ['accepted'],
        ]);

        if (! $this->cancelStripeSubscriptionIfNeeded($user)) {
            return back()->withErrors([
                'password' => '契約状態を安全に確認できないため、アカウント削除を中止しました。時間をおいてもう一度お試しください。',
            ], 'userDeletion');
        }

        $images = $this->productImages($user->id);
        Auth::logout();
        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
        $this->deleteProductImages($images, $user->id);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function cancelStripeSubscriptionIfNeeded(User $user): bool
    {
        $secret = config('furimadeck.billing.stripe_secret');
        $customerId = $user->stripe_customer_id;
        $subscriptionId = $user->stripe_subscription_id;
        $currentStatuses = ['active', 'trialing', 'past_due', 'unpaid', 'paused'];

        if ((! is_string($customerId) || $customerId === '') && (! is_string($subscriptionId) || $subscriptionId === '')) {
            return ! in_array($user->subscription_status, $currentStatuses, true);
        }
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            if (is_string($customerId) && $customerId !== '') {
                $response = Http::timeout(10)->withToken($secret)
                    ->acceptJson()
                    ->get(rtrim((string) config('furimadeck.billing.stripe_api_base'), '/').'/subscriptions', [
                        'customer' => $customerId,
                        'status' => 'all',
                        'limit' => self::STRIPE_SUBSCRIPTION_LIST_LIMIT,
                    ]);
                $subscriptions = $response->successful() ? $response->json('data') : null;
                if (! is_array($subscriptions) || $response->json('has_more') === true) {
                    return false;
                }
                foreach ($subscriptions as $subscription) {
                    $currentSubscriptionId = data_get($subscription, 'id');
                    $status = data_get($subscription, 'status');
                    if (! is_string($currentSubscriptionId) || ! is_string($status) || ! in_array($status, $currentStatuses, true)) {
                        continue;
                    }
                    if (! Http::asForm()->timeout(10)->withToken($secret)
                        ->delete(rtrim((string) config('furimadeck.billing.stripe_api_base'), '/').'/subscriptions/'.rawurlencode($currentSubscriptionId))
                        ->successful()) {
                        return false;
                    }
                }

                return true;
            }

            return is_string($subscriptionId) && $subscriptionId !== ''
                && Http::asForm()->timeout(10)->withToken($secret)
                    ->delete(rtrim((string) config('furimadeck.billing.stripe_api_base'), '/').'/subscriptions/'.rawurlencode($subscriptionId))
                    ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return Collection<int, object> */
    private function auctionImages(int $userId): Collection
    {
        if (! Schema::hasTable('auction_items')) {
            return collect();
        }

        return DB::table('auction_items')
            ->where('user_id', $userId)
            ->get(['image_path', 'sold_image_path']);
    }

    private function deleteRowsForUser(string $table, int $userId): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $userId)->delete();
        }
    }

    /** @param Collection<int, object> $images */
    private function deleteAuctionImages(Collection $images): void
    {
        $paths = $images->flatMap(fn (object $image) => [$image->image_path, $image->sold_image_path])
            ->filter(fn (?string $path) => is_string($path) && str_starts_with($path, 'auction-items/') && ! str_contains($path, '..'))
            ->unique()
            ->values()
            ->all();

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }

    /** @return Collection<int, ProductImage> */
    private function productImages(int $userId): Collection
    {
        return ProductImage::query()
            ->whereHas('product', fn ($query) => $query->where('user_id', $userId))
            ->get(['original_path', 'derived_path', 'storage_disk']);
    }

    /** @param Collection<int, ProductImage> $images */
    private function deleteProductImages(Collection $images, int $userId): void
    {
        $images->groupBy(fn (ProductImage $image) => $image->storageDisk())
            ->each(function (Collection $images, string $disk) use ($userId): void {
                $paths = $images->flatMap(fn (ProductImage $image) => [$image->original_path, $image->derived_path])
                    ->filter(fn (?string $path) => is_string($path) && str_starts_with($path, 'products/'.$userId.'/') && ! str_contains($path, '..'))
                    ->unique()
                    ->values()
                    ->all();
                Storage::disk($disk)->delete($paths);
            });
    }
}
