@php
    $pageSeo = config('seo.pages')['legal.commercial'] ?? [];
    $operatorName = config('legal.operator_name');
    $responsiblePerson = config('legal.responsible_person');
    $operatorAddress = config('legal.operator_address');
    $operatorPhone = config('legal.operator_phone');
    $contactEmail = config('legal.contact_email');
    $premiumPrice = (int) config('furimadeck.billing.monthly_price_jpy');
    $trialDays = (int) config('furimadeck.billing.trial_period_days');
@endphp

<x-legal-layout title="特定商取引法に基づく表記" eyebrow="COMMERCIAL TRANSACTIONS" :description="$pageSeo['description']">
    <h2>販売事業者</h2>
    <dl class="not-prose mt-5 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50 text-sm font-bold text-slate-700">
        <div class="grid gap-2 p-4 md:grid-cols-[14rem_1fr]">
            <dt class="text-slate-950">事業者名</dt>
            <dd>{{ $operatorName ?: '請求があった場合、遅滞なく開示します。' }}</dd>
        </div>
        <div class="grid gap-2 p-4 md:grid-cols-[14rem_1fr]">
            <dt class="text-slate-950">代表者名または運営責任者</dt>
            <dd>{{ $responsiblePerson ?: '請求があった場合、遅滞なく開示します。' }}</dd>
        </div>
        <div class="grid gap-2 p-4 md:grid-cols-[14rem_1fr]">
            <dt class="text-slate-950">所在地</dt>
            <dd>{{ $operatorAddress ?: '請求があった場合、遅滞なく開示します。' }}</dd>
        </div>
        <div class="grid gap-2 p-4 md:grid-cols-[14rem_1fr]">
            <dt class="text-slate-950">電話番号</dt>
            <dd>{{ $operatorPhone ?: '請求があった場合、遅滞なく開示します。' }}</dd>
        </div>
        <div class="grid gap-2 p-4 md:grid-cols-[14rem_1fr]">
            <dt class="text-slate-950">連絡先メールアドレス</dt>
            <dd>
                <a href="{{ route('legal.contact') }}" class="text-cyan-800 underline hover:text-cyan-950">お問い合わせフォーム</a>
                @if ($contactEmail)
                    <span class="ml-2">{{ $contactEmail }}</span>
                @endif
            </dd>
        </div>
    </dl>

    <h2>提供サービス</h2>
    <p>FurimaDeckは、フリマ販売向けの商品登録、画像管理、SOLD管理、CSV管理、売上・利益分析、ジャンル別集計、重複チェックなどを提供する収益管理サービスです。</p>

    <h2>販売価格</h2>
    <ul>
        <li>Freeプラン: 無料</li>
        <li>Premiumプラン: {{ $trialDays }}日間無料お試し後、月額{{ number_format($premiumPrice) }}円（税込）</li>
    </ul>
    <p>Premiumプランでは、商品登録数とカテゴリ登録数の制限がなくなり、CSV管理、売上分析、ジャンル別売上分析、重複チェックなどのPremium機能を利用できます。無料期間中に、フリマ販売の登録、管理、振り返りがどれだけ楽になるかを確認できます。</p>

    <h2>商品代金以外の必要料金</h2>
    <p>当サービスが追加で請求する料金はありません。サービス利用に必要な通信料、インターネット接続料金、端末費用などは利用者の負担となります。デジタルサービスのため、送料は発生しません。</p>

    <h2>代金の支払時期・方法</h2>
    <p>Premiumプランの支払いはStripeが提供する決済画面を通じて行います。利用できる支払い方法はStripeの決済画面に表示される内容に従います。</p>
    <p>初回の支払いは{{ $trialDays }}日間無料お試し終了後に発生し、その後は解約されるまで1か月ごとに自動更新されます。ただし、過去に無料お試しを利用済みの場合は、Stripeの決済画面に表示される条件に従います。</p>

    <h2>サービス提供時期</h2>
    <p>Premiumプランは、Stripeでの申込完了後、通常すぐに{{ $trialDays }}日間無料お試しとして利用できます。通信状況、Stripeの処理状況、メンテナンス等により、反映に時間がかかる場合があります。</p>

    <h2>解約・契約管理</h2>
    <p>Premiumプランは、ログイン後の「契約・解約」画面からStripeの契約管理画面へ進み、いつでも解約手続きができます。契約期間は月単位で、解約されるまで1か月ごとに自動更新されます。</p>
    <p>解約後のPremium機能の利用可否は、Stripeの契約管理画面で選択・表示される解約条件に従います。期間終了時に解約する場合は、現在の請求期間終了までPremium機能を利用できます。即時解約の場合は、解約完了時点でPremium機能を利用できなくなる場合があります。</p>

    <h2>返品・キャンセル・返金</h2>
    <p>サービスの性質上、決済完了後のキャンセル、返金、日割り返金は原則として行いません。ただし、法令上必要な場合または当サービスが個別に認めた場合はこの限りではありません。</p>

    <h2>動作環境</h2>
    <p>最新版の主要ブラウザでの利用を推奨します。端末、ブラウザ、通信環境によっては、一部機能を利用できない場合があります。</p>
</x-legal-layout>
