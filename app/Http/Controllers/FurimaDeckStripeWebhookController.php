<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class FurimaDeckStripeWebhookController extends Controller
{
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        if (! (bool) config('furimadeck.cutover_enabled')) {
            return response('Not found', 404);
        }

        $webhookSecret = config('furimadeck.billing.stripe_webhook_secret');
        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return response('Webhook secret missing', 500);
        }

        $payload = $request->getContent();
        if (! $this->hasValidSignature($payload, (string) $request->header('Stripe-Signature', ''), $webhookSecret)) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        $eventId = data_get($event, 'id');
        $eventType = data_get($event, 'type');
        if (! is_array($event) || ! is_string($eventId) || $eventId === '') {
            return response('Invalid payload', 400);
        }

        if (! $this->startProcessing($eventId, is_string($eventType) ? $eventType : null, $payload)) {
            return response('OK');
        }

        try {
            $object = data_get($event, 'data.object', []);
            $eventCreatedAt = is_numeric(data_get($event, 'created')) ? (int) data_get($event, 'created') : null;

            match ($eventType) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($object),
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted' => $this->syncSubscription($object, $eventCreatedAt),
                default => null,
            };
        } catch (Throwable) {
            $this->forgetProcessing($eventId);

            return response('Webhook processing failed', 500);
        }

        $this->finishProcessing($eventId);

        return response('OK');
    }

    private function handleCheckoutCompleted(mixed $session): void
    {
        if (! is_array($session)) {
            return;
        }

        $user = $this->resolveUser($session);
        $subscriptionId = data_get($session, 'subscription');
        if ($user && is_string($subscriptionId) && $subscriptionId !== '') {
            $user->forceFill(['stripe_subscription_id' => $subscriptionId])->save();
        }
    }

    private function syncSubscription(mixed $subscription, ?int $eventCreatedAt): void
    {
        if (! is_array($subscription)) {
            return;
        }

        $user = $this->resolveUser($subscription);
        if (! $user) {
            return;
        }

        $eventCreated = $eventCreatedAt === null ? null : Carbon::createFromTimestamp($eventCreatedAt);
        if ($eventCreated !== null
            && $user->stripe_subscription_event_created_at !== null
            && $eventCreated->lt($user->stripe_subscription_event_created_at)) {
            return;
        }

        $subscriptionId = data_get($subscription, 'id');
        $customerId = data_get($subscription, 'customer');
        $status = (string) data_get($subscription, 'status', '');
        $periodEnd = is_numeric(data_get($subscription, 'current_period_end'))
            ? Carbon::createFromTimestamp((int) data_get($subscription, 'current_period_end'))
            : null;
        $hasRemainingAccess = $status === 'canceled' && $periodEnd !== null && $periodEnd->isFuture();
        $isActive = in_array($status, ['active', 'trialing'], true) || $hasRemainingAccess;
        $hasTrial = $status === 'trialing'
            || is_numeric(data_get($subscription, 'trial_start'))
            || is_numeric(data_get($subscription, 'trial_end'));

        $user->forceFill([
            'subscription_plan' => $isActive ? User::SUBSCRIPTION_ACTIVE : User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => $status !== '' ? $status : null,
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'premium_started_at' => $isActive && $user->premium_started_at === null ? now() : $user->premium_started_at,
            'premium_ends_at' => $periodEnd ?? $user->premium_ends_at,
            'trial_used_at' => $hasTrial && $user->trial_used_at === null ? now() : $user->trial_used_at,
            'stripe_subscription_event_created_at' => $eventCreated ?? $user->stripe_subscription_event_created_at,
        ])->save();
    }

    private function resolveUser(array $stripeObject): ?User
    {
        $customerId = data_get($stripeObject, 'customer');
        if (! is_string($customerId) || $customerId === '') {
            return null;
        }

        $user = User::query()->where('stripe_customer_id', $customerId)->first();
        if ($user) {
            return $user;
        }

        $userId = data_get($stripeObject, 'metadata.user_id') ?: data_get($stripeObject, 'client_reference_id');
        if (! is_numeric($userId)) {
            return null;
        }

        $user = User::query()->find((int) $userId);
        if (! $user || ($user->stripe_customer_id !== null && $user->stripe_customer_id !== $customerId)) {
            return null;
        }

        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $user;
    }

    private function startProcessing(string $eventId, ?string $eventType, string $payload): bool
    {
        try {
            DB::table('furimadeck_stripe_webhook_events')->insert([
                'stripe_event_id' => $eventId,
                'event_type' => $eventType,
                'payload_hash' => hash('sha256', $payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEvent($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function finishProcessing(string $eventId): void
    {
        DB::table('furimadeck_stripe_webhook_events')->where('stripe_event_id', $eventId)->update([
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function forgetProcessing(string $eventId): void
    {
        DB::table('furimadeck_stripe_webhook_events')->where('stripe_event_id', $eventId)->whereNull('processed_at')->delete();
    }

    private function isDuplicateEvent(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || in_array($driverCode, ['1062', '19'], true);
    }

    private function hasValidSignature(string $payload, string $signature, string $webhookSecret): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === 't' && is_string($value)) {
                $timestamp = $value;
            }
            if ($key === 'v1' && is_string($value) && $value !== '') {
                $signatures[] = $value;
            }
        }

        if (! is_string($timestamp) || $signatures === [] || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$payload, $webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($computed, $signature)) {
                return true;
            }
        }

        return false;
    }
}
