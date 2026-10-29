<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class StripeWebhookController extends Controller
{
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            Log::warning('Stripe webhook secret is not configured.');

            return response('Webhook secret missing', 500);
        }

        if (! $this->hasValidSignature($payload, $signature, $webhookSecret)) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return response('Invalid payload', 400);
        }

        $eventId = data_get($event, 'id');
        $eventType = data_get($event, 'type');

        if (! is_string($eventId) || $eventId === '') {
            return response('Missing event id', 400);
        }

        if (! $this->startWebhookEventProcessing($eventId, is_string($eventType) ? $eventType : null, $payload)) {
            return response('OK');
        }

        try {
            match (data_get($event, 'type')) {
                'checkout.session.completed' => $this->handleCheckoutCompleted(data_get($event, 'data.object', [])),
                'customer.subscription.created' => $this->syncCurrentSubscription(data_get($event, 'data.object', [])),
                'customer.subscription.updated' => $this->syncCurrentSubscription(data_get($event, 'data.object', [])),
                'customer.subscription.deleted' => $this->syncCurrentSubscription(data_get($event, 'data.object', [])),
                'customer.subscription.trial_will_end' => $this->handleTrialWillEnd(data_get($event, 'data.object', [])),
                'invoice.paid' => $this->handleInvoiceSubscriptionSync(data_get($event, 'data.object', []), 'paid'),
                'invoice.payment_failed' => $this->handleInvoiceSubscriptionSync(data_get($event, 'data.object', []), 'payment_failed'),
                'invoice.payment_action_required' => $this->handleInvoiceSubscriptionSync(data_get($event, 'data.object', []), 'payment_action_required'),
                default => null,
            };
        } catch (Throwable $exception) {
            $this->forgetWebhookEventProcessing($eventId);

            throw $exception;
        }

        $this->finishWebhookEventProcessing($eventId);

        return response('OK');
    }

    private function startWebhookEventProcessing(string $eventId, ?string $eventType, string $payload): bool
    {
        try {
            DB::table('stripe_webhook_events')->insert([
                'stripe_event_id' => $eventId,
                'event_type' => $eventType,
                'payload_hash' => hash('sha256', $payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateWebhookEvent($exception)) {
                Log::info('Duplicate Stripe webhook event ignored.', [
                    'stripe_event_id' => $eventId,
                    'event_type' => $eventType,
                ]);

                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function finishWebhookEventProcessing(string $eventId): void
    {
        DB::table('stripe_webhook_events')
            ->where('stripe_event_id', $eventId)
            ->update([
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function forgetWebhookEventProcessing(string $eventId): void
    {
        DB::table('stripe_webhook_events')
            ->where('stripe_event_id', $eventId)
            ->whereNull('processed_at')
            ->delete();
    }

    private function isDuplicateWebhookEvent(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || in_array($driverCode, ['1062', '19'], true);
    }

    private function handleCheckoutCompleted(mixed $session): void
    {
        if (! is_array($session)) {
            return;
        }

        $subscriptionId = data_get($session, 'subscription');
        $customerId = data_get($session, 'customer');
        $userId = data_get($session, 'metadata.user_id') ?: data_get($session, 'client_reference_id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $subscription = $this->fetchSubscription($subscriptionId);

        if ($subscription === []) {
            return;
        }

        if (is_numeric($userId)) {
            $subscription['metadata']['user_id'] = (string) $userId;
        }

        if (is_string($customerId)) {
            $subscription['customer'] = $customerId;
        }

        $this->syncSubscription($subscription);
    }

    private function syncCurrentSubscription(mixed $subscription): void
    {
        if (! is_array($subscription)) {
            return;
        }

        $subscriptionId = data_get($subscription, 'id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $currentSubscription = $this->fetchSubscription($subscriptionId);

        if ($currentSubscription !== []) {
            $userId = data_get($subscription, 'metadata.user_id');

            if (is_numeric($userId) && ! is_numeric(data_get($currentSubscription, 'metadata.user_id'))) {
                $currentSubscription['metadata']['user_id'] = (string) $userId;
            }

            $this->syncSubscription($currentSubscription);

            return;
        }

        if ($this->hasStripeSecret()) {
            throw new RuntimeException('Stripe subscription current state could not be fetched.');
        }

        $this->syncSubscription($subscription);
    }

    private function handleTrialWillEnd(mixed $subscription): void
    {
        if (! is_array($subscription)) {
            return;
        }

        Log::info('Stripe subscription trial will end.', [
            'subscription_id' => data_get($subscription, 'id'),
            'customer_id' => data_get($subscription, 'customer'),
        ]);
    }

    private function handleInvoiceSubscriptionSync(mixed $invoice, string $reason): void
    {
        if (! is_array($invoice)) {
            return;
        }

        $subscriptionId = data_get($invoice, 'subscription');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            Log::info('Stripe invoice event did not include subscription id.', [
                'reason' => $reason,
                'invoice_id' => data_get($invoice, 'id'),
            ]);

            return;
        }

        $subscription = $this->fetchSubscription($subscriptionId);

        if ($subscription === []) {
            if ($this->hasStripeSecret()) {
                throw new RuntimeException('Stripe invoice subscription current state could not be fetched.');
            }

            return;
        }

        Log::info('Stripe invoice event synced subscription state.', [
            'reason' => $reason,
            'invoice_id' => data_get($invoice, 'id'),
            'subscription_id' => $subscriptionId,
        ]);

        $this->syncSubscription($subscription);
    }

    private function syncSubscription(mixed $subscription): void
    {
        if (! is_array($subscription)) {
            return;
        }

        $subscriptionId = data_get($subscription, 'id');
        $customerId = data_get($subscription, 'customer');
        $userId = data_get($subscription, 'metadata.user_id');

        $user = null;

        if (is_numeric($userId)) {
            $user = User::query()->find((int) $userId);
        }

        if (! $user && is_string($customerId)) {
            $user = User::query()->where('stripe_customer_id', $customerId)->first();
        }

        if (! $user) {
            return;
        }

        $status = (string) data_get($subscription, 'status', '');
        $periodEnd = data_get($subscription, 'current_period_end');
        $trialStart = data_get($subscription, 'trial_start');
        $trialEnd = data_get($subscription, 'trial_end');
        $isActive = in_array($status, ['active', 'trialing'], true);
        $usedTrial = $status === 'trialing' || is_numeric($trialStart) || is_numeric($trialEnd);

        $user->forceFill([
            'subscription_plan' => $isActive ? User::SUBSCRIPTION_ACTIVE : User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => $status ?: null,
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'premium_started_at' => $isActive && $user->premium_started_at === null ? now() : $user->premium_started_at,
            'premium_ends_at' => is_numeric($periodEnd) ? Carbon::createFromTimestamp((int) $periodEnd) : $user->premium_ends_at,
            'trial_used_at' => $usedTrial && $user->trial_used_at === null ? now() : $user->trial_used_at,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSubscription(string $subscriptionId): array
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions/'.rawurlencode($subscriptionId));
        } catch (Throwable $exception) {
            Log::warning('Stripe webhook subscription fetch failed.', [
                'subscription_id' => $subscriptionId,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function hasValidSignature(string $payload, string $signature, string $webhookSecret): bool
    {
        $timestamp = null;
        $expectedSignatures = [];

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ($key === 't' && is_string($value)) {
                $timestamp = $value;
            }

            if ($key === 'v1' && is_string($value) && $value !== '') {
                $expectedSignatures[] = $value;
            }
        }

        if (! is_string($timestamp) || $expectedSignatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $computedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        foreach ($expectedSignatures as $expectedSignature) {
            if (hash_equals($computedSignature, $expectedSignature)) {
                return true;
            }
        }

        return false;
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }

    private function hasStripeSecret(): bool
    {
        $secret = config('services.stripe.secret');

        return is_string($secret) && $secret !== '';
    }
}
