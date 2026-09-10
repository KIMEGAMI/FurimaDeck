<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\AuctionItem;
use App\Models\AuctionItemImage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'canDeleteAccount' => ! $this->isProtectedAccount($request->user()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($this->isProtectedAccount($user)) {
            return Redirect::route('profile.edit')
                ->with('status', 'protected-account');
        }

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        if (! $this->cancelStripeSubscriptionIfNeeded($user)) {
            return Redirect::route('profile.edit')
                ->withErrors([
                    'password' => 'Stripe契約の解除に失敗したため、アカウント削除を中止しました。時間をおいて再度お試しください。',
                ], 'userDeletion');
        }

        Auth::logout();

        $this->deleteUserAuctionItemImages($user->id);

        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function isProtectedAccount(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $demoEmail = config('demo.user_email');

        return $user->isAdmin() || (is_string($demoEmail) && $demoEmail !== '' && $user->email === $demoEmail);
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
            Log::warning('User account deletion Stripe cancellation failed.', [
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
            Log::warning('User account deletion Stripe subscription lookup failed.', [
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
