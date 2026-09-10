<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuctionItem;
use App\Models\AuctionItemImage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class UserController extends Controller
{
    private const USERS_PER_PAGE = 20;

    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.users.index', [
            'users' => User::query()
                ->withCount('auctionItems')
                ->latest('created_at')
                ->paginate(self::USERS_PER_PAGE),
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $admin = $this->authorizeAdmin($request);

        if ($admin->is($user)) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', '自分自身のアカウントは削除できません。');
        }

        if ($user->isAdmin()) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', '管理者アカウントは削除できません。');
        }

        $validated = $request->validate([
            'confirmation_email' => ['required', 'string', 'max:255'],
        ]);

        if (! hash_equals($user->email, $validated['confirmation_email'])) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', '確認用メールアドレスが一致しないため、ユーザを削除しませんでした。');
        }

        if (! $this->cancelStripeSubscriptionIfNeeded($user)) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'Stripe契約の停止に失敗したため、ユーザを削除しませんでした。');
        }

        $this->deleteUserAuctionItemImages($user->id);

        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'ユーザを削除しました。');
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user?->isAdmin(), 403);

        return $user;
    }

    private function deleteUserAuctionItemImages(int $userId): void
    {
        $paths = AuctionItemImage::query()
            ->whereHas('auctionItem', fn ($query) => $query->where('user_id', $userId))
            ->pluck('path')
            ->filter(fn (?string $path) => $this->isSafeAuctionItemImagePath($path))
            ->all();

        AuctionItem::query()
            ->where('user_id', $userId)
            ->select(['id', 'image_path', 'sold_image_path'])
            ->chunkById(100, function ($items) use (&$paths): void {
                $paths = array_merge($paths, $items
                    ->flatMap(fn (AuctionItem $item) => [$item->image_path, $item->sold_image_path])
                    ->filter(fn (?string $path) => $this->isSafeAuctionItemImagePath($path))
                    ->values()
                    ->all());
            });

        if ($paths !== []) {
            Storage::disk('public')->delete(array_values(array_unique($paths)));
        }
    }

    private function isSafeAuctionItemImagePath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        if (str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }

        return str_starts_with($path, 'auction-items/');
    }

    private function cancelStripeSubscriptionIfNeeded(User $user): bool
    {
        try {
            $subscriptionIds = $this->stripeSubscriptionIdsToCancel($user);

            if ($subscriptionIds === []) {
                return true;
            }

            $secret = config('services.stripe.secret');

            if (! is_string($secret) || $secret === '') {
                return false;
            }

            foreach ($subscriptionIds as $subscriptionId) {
                $response = Http::asForm()
                    ->timeout(10)
                    ->withToken($secret)
                    ->delete($this->stripeApiBase().'/subscriptions/'.rawurlencode($subscriptionId));

                if ($response->failed()) {
                    return false;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Admin user deletion Stripe cancellation failed.', [
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function stripeSubscriptionIdsToCancel(User $user): array
    {
        $subscriptionIds = [];

        if (is_string($user->stripe_subscription_id) && $user->stripe_subscription_id !== '') {
            $subscriptionIds[] = $user->stripe_subscription_id;
        }

        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            $customerSubscriptionIds = $this->activeStripeSubscriptionIdsForCustomer($user->stripe_customer_id);

            if ($customerSubscriptionIds === null) {
                throw new RuntimeException('Stripe subscription lookup failed.');
            }

            $subscriptionIds = [
                ...$subscriptionIds,
                ...$customerSubscriptionIds,
            ];
        }

        return array_values(array_unique($subscriptionIds));
    }

    /**
     * @return array<int, string>|null
     */
    private function activeStripeSubscriptionIdsForCustomer(string $customerId): ?array
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions', [
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 100,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Admin user deletion Stripe subscription lookup failed.', [
                'error_class' => $exception::class,
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $subscriptions = $response->json('data');

        if (! is_array($subscriptions)) {
            return null;
        }

        return collect($subscriptions)
            ->filter(fn (mixed $subscription): bool => is_array($subscription)
                && in_array(data_get($subscription, 'status'), ['active', 'trialing', 'past_due', 'unpaid', 'paused'], true)
                && is_string(data_get($subscription, 'id'))
                && data_get($subscription, 'id') !== '')
            ->map(fn (array $subscription): string => (string) data_get($subscription, 'id'))
            ->values()
            ->all();
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }
}
