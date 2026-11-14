<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckFreeeApiReadService
{
    /** @param array<string, mixed> $query */
    public function get(AccountingConnection $connection, string $url, array $query = []): Response
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('freee APIの接続先設定が正しくありません。');
        }

        $user = $connection->user;
        if (! $user instanceof User || $connection->provider !== 'freee') {
            throw new RuntimeException('freee連携情報を確認できません。');
        }

        $rejectedAccessToken = (string) $connection->access_token;
        $response = $this->request($connection, $url, $query);
        if ($response->status() !== 401) {
            return $response;
        }

        $errorCode = $this->errorCode($response);
        if ($errorCode !== 'expired_access_token') {
            if ($errorCode === 're_authorization_required') {
                app(FurimaDeckAccountingOAuthService::class)->markExpiredIfCurrentTokenMatches(
                    $user,
                    'freee',
                    $rejectedAccessToken,
                    $errorCode,
                );
            }

            throw new RuntimeException($this->unauthorizedMessage($errorCode));
        }

        $freshConnection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded(
            $user,
            'freee',
            true,
            $rejectedAccessToken,
        );
        $connection->setRawAttributes($freshConnection->getAttributes(), true);

        $response = $this->request($connection, $url, $query);
        if ($response->status() === 401) {
            $errorCode = $this->errorCode($response);
            if (in_array($errorCode, ['expired_access_token', 're_authorization_required'], true)) {
                app(FurimaDeckAccountingOAuthService::class)->markExpiredIfCurrentTokenMatches(
                    $user,
                    'freee',
                    (string) $connection->access_token,
                    $errorCode,
                );
            }

            throw new RuntimeException($errorCode === 'expired_access_token'
                ? 'freeeの認証を更新しましたが、読み取りAPIが再び拒否されました。再連携してください。'
                : $this->unauthorizedMessage($errorCode));
        }

        return $response;
    }

    /** @param array<string, mixed> $query */
    private function request(AccountingConnection $connection, string $url, array $query): Response
    {
        return Http::acceptJson()
            ->withToken((string) $connection->access_token)
            ->timeout(15)
            ->get($url, $query);
    }

    private function errorCode(Response $response): ?string
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $codes = [data_get($payload, 'code')];
        foreach ((array) ($payload['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $codes = [...$codes, ...(array) ($error['codes'] ?? [])];
            }
        }

        foreach ($codes as $code) {
            if (is_string($code) && preg_match('/\A[a-z0-9_]{1,64}\z/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    private function unauthorizedMessage(?string $errorCode): string
    {
        return match ($errorCode) {
            're_authorization_required' => 'freeeアプリの権限変更により再認可が必要です。freeeと再連携してください。',
            'user_do_not_have_permission' => 'freee事業所で連携ユーザーに必要な権限がありません。事業所管理者に確認してください。',
            'freee_plan_limit', 'not_available_plan_limited_app' => 'freeeの現在のプランではこのAPIを利用できません。プランを確認してください。',
            'company_not_found' => 'freeeの事業所設定を確認できません。仕訳設定で事業所を確認してください。',
            default => 'freeeが認証または権限を拒否しました。連携設定と事業所権限を確認してください。',
        };
    }
}
