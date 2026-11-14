@php
    $pageSeo = config('seo.pages')['legal.faq'] ?? [];
    $premiumPrice = (int) config('furimadeck.billing.monthly_price_jpy');
    $trialDays = (int) config('furimadeck.billing.trial_period_days');
    $staleInventoryDays = (int) config('furimadeck.inventory.long_term_days', 30);
    $faqs = [
        ['FreeプランとPremiumプランの違いは何ですか？', "Freeプランは無料で、商品登録50件まで利用でき、販売記録と在庫更新も行えます。Premiumプランは{$trialDays}日間無料お試し後、月額".number_format($premiumPrice).'円（税込）で、商品登録数の制限がなくなり、CSV登録・出力、売上分析、会計CSVを利用できます。'],
        ['CSVで一括登録できますか？', 'できます。FurimaDeck形式CSV、ヤフオクCSV変換、メルカリShops CSV変換などに対応しています。'],
        ['スマートフォンから商品画像を登録できますか？', 'できます。商品登録画面からカメラを起動して撮影した画像を登録できます。'],
        ['重複した商品を削除できますか？', '重複チェック画面から候補を確認し、残す商品を選んで不要な重複を削除できます。'],
        ["{$staleInventoryDays}日以上売れていない商品を確認できますか？", "商品一覧で未販売の日数を指定して検索できます。ダッシュボードでは出品日から{$staleInventoryDays}日以上経過した在庫をお知らせします。未出品の商品は対象外です。"],
        ['ヤフオクやメルカリShopsのCSVに対応していますか？', 'ヤフオク売上CSV、メルカリShops CSVの変換登録に対応しています。外部CSVをFurimaDeck形式CSVとして整理し、販売記録へ反映できます。'],
        ['売上と利益はどのように確認できますか？', '売上、仕入れ価格、手数料、送料、実利益を集計し、月次やジャンル別の分析に利用できます。'],
        ['Premiumの契約管理や解約はできますか？', 'ログイン後の契約管理画面からStripeの契約管理画面へ進み、契約内容の確認や解約を行えます。7日間無料お試し中に、商品登録、CSV、売上分析、重複チェックまで実際の運用で確認できます。'],
        ['アカウントを削除すると画像も削除されますか？', 'ユーザー削除時には、そのユーザーが登録した商品画像も削除する設計です。必要なデータは事前に控えてください。'],
        ['メンテナンス中でも管理者はログインできますか？', '管理者はログイン画面から管理画面へ入り、メンテナンスモードを解除できます。'],
    ];
    $schema = [[
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($faqs)->map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]],
        ])->values()->all(),
    ]];
@endphp

<x-legal-layout title="よくある質問" eyebrow="FAQ" :description="$pageSeo['description']" :schema="$schema">
    @foreach ($faqs as [$question, $answer])
        <h2>{{ $question }}</h2>
        <p>{{ $answer }}</p>
    @endforeach
</x-legal-layout>
