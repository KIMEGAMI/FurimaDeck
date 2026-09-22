<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckAccountingOAuthService
{
    private const PROVIDERS = ['freee', 'money_forward'];

    public function config(string $provider): array
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return (array) config('furimadeck.accounting.oauth.'.$provider);
    }

    public function ensureConfigured(string $provider): array
    {
        $settings = $this->config($provider);
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                throw new RuntimeException($provider.'のOAuth設定が未設定です。');
            }
        }

        return $settings;
    }

    public function authorizationUrl(User $user, string $provider, string $state): string
    {
        $settings = $this->ensureConfigured($provider);
        $query = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'scope' => $settings['scope'],
            'state' => $state,
        ];
        if ($provider === 'freee') {
            $query['prompt'] = 'select_company';
        }

        return $settings['authorize_url'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(User $user, string $provider, string $code): AccountingConnection
    {
        $settings = $this->ensureConfigured($provider);
        $request = $this->http();
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $settings['redirect_uri'],
        ];
        $response = $provider === 'money_forward'
            ? $request->withBasicAuth($settings['client_id'], $settings['client_secret'])->asForm()->post($settings['token_url'], $payload)
            : $request->asForm()->post($settings['token_url'], [...$payload, 'client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret']]);

        $connection = $this->storeTokenResponse($user, $provider, $response);
        if ($provider === 'money_forward') {
            $connection = $this->hydrateMoneyForwardTenant($connection);
        }

        return $connection;
    }

    public function refreshIfNeeded(User $user, string $provider): AccountingConnection
    {
        $connection = $user->accountingConnections()->where('provider', $provider)->firstOrFail();
        if ($connection->expires_at === null || ($connection->expires_at->isFuture() && $connection->expires_at->greaterThan(now()->addMinute()))) {
            return $connection;
        }
        if (trim((string) $connection->refresh_token) === '') {
            $connection->update(['status' => 'expired', 'last_error' => 'refresh_token_missing']);
            throw new RuntimeException($provider.'の更新トークンがありません。再連携してください。');
        }

        $settings = $this->ensureConfigured($provider);
        $request = $this->http();
        $payload = ['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token];
        $response = $provider === 'money_forward'
            ? $request->withBasicAuth($settings['client_id'], $settings['client_secret'])->asForm()->post($settings['token_url'], $payload)
            : $request->asForm()->post($settings['token_url'], [...$payload, 'client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret']]);

        try {
            $refreshed = $this->storeTokenResponse($user, $provider, $response, $connection);

            return $provider === 'money_forward'
                ? $this->hydrateMoneyForwardTenant($refreshed)
                : $refreshed;
        } catch (RuntimeException $exception) {
            $connection->update(['status' => 'expired', 'last_error' => 'refresh_failed']);

            throw $exception;
        }
    }

    public function disconnect(User $user, string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $user->accountingConnections()->where('provider', $provider)->delete();
    }

    private function hydrateMoneyForwardTenant(AccountingConnection $connection): AccountingConnection
    {
        $base = (string) config('furimadeck.accounting.oauth.money_forward.api_base', 'https://api.biz.moneyforward.com');
        try {
            $response = $this->http()
                ->withToken((string) $connection->access_token)
                ->get(rtrim($base, '/').'/v2/tenant');
        } catch (\Throwable) {
            $connection->update(['last_error' => 'tenant_lookup_failed']);

            return $connection;
        }

        if ($response->successful() && is_string($response->json('tenant_code')) && trim($response->json('tenant_code')) !== '') {
            $connection->update([
                'external_account_id' => trim($response->json('tenant_code')),
                'last_error' => null,
            ]);

            return $connection->refresh();
        }

        $connection->update(['last_error' => 'tenant_lookup_failed']);

        return $connection;
    }

    private function storeTokenResponse(User $user, string $provider, mixed $response, ?AccountingConnection $existing = null): AccountingConnection
    {
        if ($response->failed()) {
            throw new RuntimeException($provider.'のOAuthトークン取得に失敗しました。');
        }

        $payload = $response->json();
        if (! is_array($payload) || trim((string) ($payload['access_token'] ?? '')) === '') {
            throw new RuntimeException($provider.'のOAuth応答が不正です。');
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 0);

        return $user->accountingConnections()->updateOrCreate(
            ['provider' => $provider],
            [
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'] ?? $existing?->refresh_token,
                'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
                'external_account_id' => isset($payload['company_id']) ? (string) $payload['company_id'] : $existing?->external_account_id,
                'status' => 'connected',
                'last_error' => null,
            ],
        );
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->retry(2, 200);
    }
}
