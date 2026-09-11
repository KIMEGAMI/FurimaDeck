<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePremiumPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasActiveSubscription()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $title = match ($routeName) {
            'auction-items.csv-import',
            'auction-items.import',
            'auction-items.import.yahoo-auctions',
            'auction-items.import.mercari-shops',
            'sales.index',
            'sales.csv',
            'sales.backup-csv',
            'sales.restore-csv',
            'sales.selling-csv' => '売上管理・CSV出力はPremiumプランで利用できます。',
            'category-sales.index' => 'ジャンル別売上分析はPremiumプランで利用できます。',
            'auction-items.duplicates',
            'auction-items.duplicates.destroy' => '重複チェックはPremiumプランで利用できます。',
            default => 'この機能はPremiumプラン限定です。',
        };
        $premiumPrice = number_format((int) config('services.stripe.subscription_amount'));
        $message = $title." Premiumは7日間無料お試し後、月額{$premiumPrice}円（税込）で、商品登録数・カテゴリ数の制限なし、CSV登録、売上分析、ジャンル別分析、重複チェックを利用できます。";

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()
            ->route('subscriptions.index')
            ->with('error', $message)
            ->with('upgrade_title', $title)
            ->with('upgrade_description', '7日間無料お試しで、Freeプランの制限解除、CSV登録、売上分析、ジャンル別分析、重複チェックを実際の運用で確認できます。')
            ->with('upgrade_features', [
                '商品登録数の制限なし',
                'カテゴリ数の制限なし',
                'CSV登録・CSV変換登録',
                '売上分析・CSV出力',
                'ジャンル別売上分析',
                '重複チェック',
            ]);
    }
}
