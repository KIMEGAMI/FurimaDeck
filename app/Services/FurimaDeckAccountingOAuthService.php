<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FurimaDeckAccountingOAuthService
{
    private const PROVIDERS = ['freee', 'money_forward'];

    private const CSV_MASTER_SCOPES = [
        'freee' => ['read'],
        'money_forward' => [
            'mfc/admin/tenant.read',
            'mfc/accounting/accounts.read',
            'mfc/accounting/taxes.read',
        ],
    ];

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
            'scope' => $this->csvMasterScope($provider, (string) ($settings['scope'] ?? '')),
            'state' => $state,
        ];
        if ($provider === 'freee') {
            $query['prompt'] = 'select_company';
        }

        return $settings['authorize_url'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function csvMasterScope(string $provider, string $configuredScope): string
    {
        $requiredScopes = self::CSV_MASTER_SCOPES[$provider] ?? [];
        $configuredScopes = preg_split('/\\s+/', trim($configuredScope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $selectedScopes = array_values(array_intersect($requiredScopes, $configuredScopes));
        if (count($selectedScopes) !== count($requiredScopes)) {
            throw new RuntimeException($provider.'のCSV用連携に必要な読取権限が設定されていません。');
        }

        return implode(' ', $selectedScopes);
    }

    public function exchange(User $user, string $provider, string $code): AccountingConnection
    {
        $settings = $this->ensureConfigured($provider);
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $settings['redirect_uri'],
        ];
        $response = $this->requestToken($provider, $settings, $payload);

        $connection = $this->storeTokenResponse($user, $provider, $response);
        if ($provider === 'money_forward') {
            $connection = $this->hydrateMoneyForwardTenant($connection);
        }

        return $connection;
    }

    public function refreshIfNeeded(User $user, string $provider, bool $force = false, ?string $rejectedAccessToken = null): AccountingConnection
    {
        $tokenRequestStarted = false;

        try {
            $result = DB::connection('furimadeck')->transaction(function () use ($user, $provider, $force, $rejectedAccessToken, &$tokenRequestStarted): array {
                $connection = AccountingConnection::on('furimadeck')
                    ->where('user_id', $user->id)
                    ->where('provider', $provider)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($connection->status === 'disconnected') {
                    return [
                        'connection' => $connection,
                        'failure' => new RuntimeException($provider.'は連携解除されています。再連携してください。'),
                        'refreshed' => false,
                    ];
                }

                if ($connection->status === 'expired') {
                    return [
                        'connection' => $connection,
                        'failure' => new RuntimeException($provider.'の認証状態を確認できません。自動再試行を停止しています。再連携してください。'),
                        'refreshed' => false,
                    ];
                }

                if ($force && $rejectedAccessToken !== null && ! hash_equals($rejectedAccessToken, (string) $connection->access_token)) {
                    return ['connection' => $connection, 'failure' => null, 'refreshed' => false];
                }

                if (! $force && ($connection->expires_at === null || ($connection->expires_at->isFuture() && $connection->expires_at->greaterThan(now()->addMinute())))) {
                    return ['connection' => $connection, 'failure' => null, 'refreshed' => false];
                }

                if (trim((string) $connection->refresh_token) === '') {
                    $connection->update(['status' => 'expired', 'last_error' => 'refresh_token_missing']);

                    return [
                        'connection' => $connection->refresh(),
                        'failure' => new RuntimeException($provider.'の更新トークンがありません。再連携してください。'),
                        'refreshed' => false,
                    ];
                }

                $settings = $this->ensureConfigured($provider);
                $payload = ['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token];
                $tokenRequestStarted = true;

                try {
                    $response = $this->requestToken($provider, $settings, $payload);
                } catch (Throwable $exception) {
                    $connection->update(['status' => 'expired', 'last_error' => 'refresh_result_unknown']);

                    return [
                        'connection' => $connection->refresh(),
                        'failure' => new RuntimeException($provider.'の認証更新結果を確認できません。二重使用防止のため自動再試行を停止しました。再連携してください。', previous: $exception),
                        'refreshed' => false,
                    ];
                }

                if ($response->failed()) {
                    $unknown = $response->serverError() || in_array($response->status(), [408, 425], true);
                    $connection->update([
                        'status' => 'expired',
                        'last_error' => $unknown ? 'refresh_result_unknown' : 'refresh_failed',
                    ]);

                    $message = $unknown
                        ? $provider.'の認証更新結果を確認できません。二重使用防止のため自動再試行を停止しました。再連携してください。'
                        : $provider.'の更新トークンが無効です。再連携してください。';

                    return [
                        'connection' => $connection->refresh(),
                        'failure' => new RuntimeException($message),
                        'refreshed' => false,
                    ];
                }

                $tokenPayload = $response->json();
                if (! is_array($tokenPayload) || trim((string) ($tokenPayload['access_token'] ?? '')) === '') {
                    $connection->update(['status' => 'expired', 'last_error' => 'refresh_result_unknown']);

                    return [
                        'connection' => $connection->refresh(),
                        'failure' => new RuntimeException($provider.'の認証更新応答を確認できません。安全のため自動再試行を停止しました。再連携してください。'),
                        'refreshed' => false,
                    ];
                }

                $refreshed = $this->storeTokenResponse($user, $provider, $response, $connection);

                return ['connection' => $refreshed, 'failure' => null, 'refreshed' => true];
            });
        } catch (Throwable $exception) {
            if ($tokenRequestStarted) {
                try {
                    AccountingConnection::on('furimadeck')
                        ->where('user_id', $user->id)
                        ->where('provider', $provider)
                        ->update(['status' => 'expired', 'last_error' => 'refresh_result_unknown']);
                } catch (Throwable) {
                    // Preserve the original failure if even the best-effort status update cannot be stored.
                }

                throw new RuntimeException($provider.'の認証更新結果を保存できませんでした。二重使用防止のため自動再試行を停止しました。再連携してください。', previous: $exception);
            }

            throw $exception;
        }

        if ($result['failure'] instanceof RuntimeException) {
            throw $result['failure'];
        }

        $connection = $result['connection'];

        return $result['refreshed'] && $provider === 'money_forward'
            ? $this->hydrateMoneyForwardTenant($connection)
            : $connection;
    }

    public function markExpiredIfCurrentTokenMatches(User $user, string $provider, string $rejectedAccessToken, string $reason): void
    {
        if (! in_array($provider, self::PROVIDERS, true)
            || ! in_array($reason, ['expired_access_token', 're_authorization_required'], true)) {
            throw new RuntimeException('会計連携の認証状態を更新できません。');
        }

        DB::connection('furimadeck')->transaction(function () use ($user, $provider, $rejectedAccessToken, $reason): void {
            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            if ($connection === null || $connection->status !== 'connected'
                || ! hash_equals($rejectedAccessToken, (string) $connection->access_token)) {
                return;
            }

            $connection->update(['status' => 'expired', 'last_error' => $reason]);
        });
    }

    public function disconnect(User $user, string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        DB::connection('furimadeck')->transaction(function () use ($user, $provider): void {
            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();
            if ($connection === null) {
                return;
            }

            $connection->update([
                'access_token' => '',
                'refresh_token' => '',
                'expires_at' => null,
                'external_account_id' => null,
                'status' => 'disconnected',
                'last_error' => null,
            ]);
        });
    }

    private function hydrateMoneyForwardTenant(AccountingConnection $connection): AccountingConnection
    {
        $base = (string) config('furimadeck.accounting.oauth.money_forward.api_base', 'https://api.biz.moneyforward.com');
        try {
            $response = $this->http()
                ->withToken((string) $connection->access_token)
                ->get(rtrim($base, '/').'/v2/tenant');
        } catch (Throwable) {
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

        return AccountingConnection::on('furimadeck')->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
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

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $payload
     */
    private function requestToken(string $provider, array $settings, array $payload): Response
    {
        $request = Http::acceptJson()->timeout(15);

        return $provider === 'money_forward'
            ? $request->withBasicAuth($settings['client_id'], $settings['client_secret'])->asForm()->post($settings['token_url'], $payload)
            : $request->asForm()->post($settings['token_url'], [...$payload, 'client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret']]);
    }
}
