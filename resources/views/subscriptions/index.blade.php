<x-app-layout>
    @php
        $isAdmin = $user->isAdmin();
        $isDemoUser = $isDemoUser ?? $user->isDemoUser();
        $hasStripeSubscription = $hasStripeSubscription ?? false;
        $stripeInvoices = $stripeInvoices ?? [];
        $stripeInvoicesUnavailable = $stripeInvoicesUnavailable ?? false;
        $canStartStripeCheckout = ! $isAdmin && ! $isDemoUser && ! $hasActiveSubscription;
        $canOpenCancellationPortal = ! $isAdmin && ! $isDemoUser && $hasActiveSubscription;
        $invoiceStatusLabels = [
            'draft' => '下書き',
            'open' => '未払い',
            'paid' => '支払い済み',
            'uncollectible' => '回収不能',
            'void' => '無効',
        ];
    @endphp

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
                    入力内容を確認してください。
                </div>
            @endif

            @if (session('error'))
                <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('status'))
                <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($isDemoUser)
                <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-bold leading-7 text-blue-900">
                    デモユーザーではStripe決済を利用できません。Premiumの申込や契約管理を確認する場合は、新しいアカウントを作成してください。
                </div>
            @endif

            @if ($canStartStripeCheckout)
                <section class="mb-6 rounded-lg border border-cyan-200 bg-white p-6 shadow-xl">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm font-black tracking-[0.18em] text-cyan-700">UPGRADE</p>
                            <h2 class="mt-2 text-2xl font-black text-slate-900">
                                {{ session('upgrade_title', 'Premiumプランで制限を解除できます。') }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm font-bold leading-7 text-slate-600">
                                {{ session('upgrade_description', 'Premiumは7日間無料お試し後、月額'.number_format($price).'円（税込）です。商品登録数とカテゴリ数の制限なし、CSV登録、売上分析、ジャンル別分析、重複チェックをまとめて使えます。') }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('subscriptions.checkout') }}" class="shrink-0">
                            @csrf
                            <input type="hidden" name="billing_terms_confirmed" value="1">
                            <button type="submit" class="rounded-lg bg-cyan-700 px-6 py-3 text-center text-sm font-black text-white shadow hover:bg-cyan-800">
                                Stripe決済画面へ進む
                            </button>
                        </form>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (session('upgrade_features', [
                            '商品登録数の制限なし',
                            'カテゴリ数の制限なし',
                            'CSV登録・CSV変換登録',
                            '売上CSV・バックアップCSV',
                            'ジャンル別売上分析',
                            '重複チェック',
                        ]) as $feature)
                            <div class="rounded-lg bg-cyan-50 px-4 py-3 text-sm font-black text-cyan-950">
                                {{ $feature }}
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-lg bg-white p-6 shadow-xl md:p-8">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-black tracking-[0.18em] text-cyan-700">STRIPE BILLING</p>
                        <h1 class="mt-2 text-3xl font-black text-slate-900 md:text-4xl">契約・解約</h1>
                        <p class="mt-4 max-w-2xl text-sm font-bold leading-7 text-slate-600">
                            Premium登録、支払い方法の変更、領収書確認、解約はStripeの安全な画面で行います。FurimaDeckはカード番号を保存しません。
                        </p>
                    </div>

                    <div class="rounded-lg border border-cyan-100 bg-cyan-50 p-5 text-center">
                        <p class="text-xs font-black tracking-wider text-cyan-700">MONTHLY</p>
                        <p class="mt-2 text-4xl font-black text-slate-900">¥{{ number_format($price) }}</p>
                        <p class="mt-1 text-xs font-bold text-slate-500">7日間無料後・月額税込</p>
                    </div>
                </div>

                <div class="mt-8 rounded-lg border border-slate-200 p-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-xl font-black text-slate-900">現在の契約状態</h2>
                            @if ($isAdmin && ! $hasStripeSubscription)
                                <p class="mt-2 text-sm font-bold text-blue-700">管理者アカウントです。Stripe契約はありません。</p>
                            @elseif ($hasActiveSubscription)
                                <p class="mt-2 text-sm font-bold text-emerald-700">Premium契約中です。</p>
                                @if ($trialEndsAt)
                                    <p class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-800">
                                        無料トライアルは {{ $trialEndsAt->timezone(config('app.timezone'))->format('Y/m/d H:i') }} に終了します
                                    </p>
                                @endif
                            @else
                                <p class="mt-2 text-sm font-bold text-slate-600">有効なPremium契約は確認できていません。</p>
                            @endif

                            @if ($user->subscription_status)
                                <p class="mt-1 text-xs font-bold text-slate-500">
                                    Stripeステータス: {{ $user->subscription_status }}
                                </p>
                            @endif
                        </div>

                        @if ($hasActiveSubscription && $hasStripeSubscription)
                            <div class="flex flex-col gap-3 sm:items-end">
                                <form method="POST" action="{{ route('subscriptions.portal') }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-slate-900 px-6 py-3 text-sm font-black text-white shadow hover:bg-slate-800">
                                        契約・支払いを管理する
                                    </button>
                                </form>
                                <p class="text-xs font-bold text-slate-500">解約もStripeの契約管理画面から行えます。</p>
                            </div>
                        @elseif ($isAdmin)
                            <div class="max-w-xs rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-bold leading-6 text-blue-900">
                                管理者権限で利用できます。Stripeの契約管理は、実際にStripe契約したユーザーのみに表示されます。
                            </div>
                        @elseif ($isDemoUser)
                            <div class="max-w-xs rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-bold leading-6 text-blue-900">
                                デモユーザーではStripe決済画面へ進めません。
                            </div>
                        @elseif ($canStartStripeCheckout)
                            <form method="POST" action="{{ route('subscriptions.checkout') }}">
                                @csrf
                                <input type="hidden" name="billing_terms_confirmed" value="1">
                                <button type="submit" class="rounded-lg bg-cyan-700 px-6 py-3 text-center text-sm font-black text-white shadow hover:bg-cyan-800">
                                    Stripe決済画面へ進む
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($hasActiveSubscription && $hasStripeSubscription)
                    <div class="mt-8 rounded-lg border border-slate-200 p-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="text-xl font-black text-slate-900">請求書</h2>
                                <p class="mt-2 text-sm font-bold leading-6 text-slate-600">
                                    Stripeで発行された直近の請求書を確認できます。
                                </p>
                            </div>
                            <form method="POST" action="{{ route('subscriptions.portal') }}">
                                @csrf
                                <button type="submit" class="rounded-lg bg-white px-4 py-2 text-sm font-black text-slate-900 ring-1 ring-slate-300 hover:bg-slate-50">
                                    Stripeで確認する
                                </button>
                            </form>
                        </div>

                        @if ($stripeInvoicesUnavailable)
                            <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-900">
                                現在、請求書を取得できませんでした。時間をおいて再表示するか、Stripeの契約管理画面で確認してください。
                            </div>
                        @elseif ($stripeInvoices === [])
                            <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm font-bold leading-7 text-slate-700">
                                まだ表示できる請求書はありません。初回決済後にStripeから発行されます。
                            </div>
                        @else
                            <div class="mt-5 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-left text-xs font-black uppercase tracking-wider text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3">発行日</th>
                                            <th class="px-4 py-3">請求書番号</th>
                                            <th class="px-4 py-3">金額</th>
                                            <th class="px-4 py-3">状態</th>
                                            <th class="px-4 py-3 text-right">確認</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 bg-white text-slate-700">
                                        @foreach ($stripeInvoices as $invoice)
                                            @php
                                                $invoiceTotal = $invoice['total'] ?? null;
                                                $invoiceCurrency = $invoice['currency'] ?? 'JPY';
                                                $invoiceAmount = is_int($invoiceTotal)
                                                    ? ($invoiceCurrency === 'JPY'
                                                        ? '¥'.number_format($invoiceTotal)
                                                        : number_format($invoiceTotal / 100, 2).' '.$invoiceCurrency)
                                                    : '未確定';
                                            @endphp
                                            <tr>
                                                <td class="whitespace-nowrap px-4 py-3 font-bold">
                                                    {{ $invoice['created_at'] ? $invoice['created_at']->timezone(config('app.timezone'))->format('Y/m/d') : '未確定' }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-3 font-bold text-slate-950">{{ $invoice['number'] }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 font-bold">{{ $invoiceAmount }}</td>
                                                <td class="whitespace-nowrap px-4 py-3 font-bold">
                                                    {{ $invoiceStatusLabels[$invoice['status']] ?? ($invoice['status'] ?: '未確定') }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                                    @if ($invoice['hosted_invoice_url'])
                                                        <a href="{{ $invoice['hosted_invoice_url'] }}" target="_blank" rel="noopener noreferrer" class="font-black text-cyan-800 underline hover:text-cyan-950">表示</a>
                                                    @endif
                                                    @if ($invoice['invoice_pdf'])
                                                        <a href="{{ $invoice['invoice_pdf'] }}" target="_blank" rel="noopener noreferrer" class="ml-4 font-black text-cyan-800 underline hover:text-cyan-950">PDF</a>
                                                    @endif
                                                    @if (! $invoice['hosted_invoice_url'] && ! $invoice['invoice_pdf'])
                                                        <span class="font-bold text-slate-400">未発行</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($canStartStripeCheckout)
                    <div id="premium-checkout-confirmation" class="mt-8 rounded-lg border border-cyan-200 bg-cyan-50 p-5">
                        <h2 class="text-lg font-black text-slate-950">Premium申込前の確認</h2>
                        <dl class="mt-4 grid gap-3 text-sm font-bold text-slate-700 sm:grid-cols-2">
                            <div class="rounded-lg bg-white p-4">
                                <dt class="text-slate-950">サービス名</dt>
                                <dd class="mt-1">FurimaDeck Premium</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4">
                                <dt class="text-slate-950">料金</dt>
                                <dd class="mt-1">7日間無料お試し後、月額¥{{ number_format($price) }}（税込）</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4">
                                <dt class="text-slate-950">更新</dt>
                                <dd class="mt-1">解約されるまで1か月ごとに自動更新</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4">
                                <dt class="text-slate-950">提供開始</dt>
                                <dd class="mt-1">Stripe申込完了後、通常すぐに7日間無料お試しを開始</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4 sm:col-span-2">
                                <dt class="text-slate-950">お試しで確認できること</dt>
                                <dd class="mt-1">商品登録数の制限解除、CSV登録、売上分析、ジャンル別分析、重複チェックを無料期間中に確認できます。</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4 sm:col-span-2">
                                <dt class="text-slate-950">解約後の利用</dt>
                                <dd class="mt-1">Stripeの契約管理画面で選択される解約条件に従います。期間終了時に解約する場合は、現在の請求期間終了までPremium機能を利用できます。</dd>
                            </div>
                            <div class="rounded-lg bg-white p-4 sm:col-span-2">
                                <dt class="text-slate-950">返金</dt>
                                <dd class="mt-1">サービスの性質上、決済完了後のキャンセル、返金、日割り返金は原則として行いません。</dd>
                            </div>
                        </dl>

                        <p class="mt-4 text-xs font-bold leading-6 text-slate-600">
                            申込前に
                            <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">利用規約</a>、
                            <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">プライバシーポリシー</a>、
                            <a href="{{ route('legal.commercial') }}" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">特定商取引法に基づく表記</a>
                            を確認してください。
                        </p>

                        <form method="POST" action="{{ route('subscriptions.checkout') }}" class="mt-5 space-y-4">
                            @csrf
                            <input type="hidden" name="billing_terms_confirmed" value="1">
                            <p class="rounded-lg bg-white p-4 text-sm font-bold leading-6 text-slate-900">
                                ボタンを押すと、7日間無料お試し、料金、自動更新、解約条件、返金条件に同意してStripeの決済画面へ進みます。
                            </p>
                            <button type="submit" class="rounded-lg bg-cyan-700 px-6 py-3 text-sm font-black text-white shadow hover:bg-cyan-800">
                                同意してStripe決済画面へ進む
                            </button>
                        </form>
                    </div>
                @endif

                <div class="mt-8 rounded-lg border border-amber-200 bg-amber-50 p-5">
                    <h2 class="text-lg font-black text-amber-900">解約について</h2>
                    <p class="mt-3 text-sm font-bold leading-7 text-amber-800">
                        解約はStripeの契約管理画面内で行います。契約中のユーザーは、下のボタンからStripeの契約管理画面へ移動できます。
                    </p>

                    @if ($canOpenCancellationPortal)
                        <form method="POST" action="{{ route('subscriptions.cancel-feedback') }}" class="mt-5">
                            @csrf
                            <button type="submit" class="rounded-lg bg-amber-700 px-6 py-3 text-sm font-black text-white shadow hover:bg-amber-800">
                                解約へ進む
                            </button>
                        </form>
                    @elseif ($isAdmin)
                        <div class="mt-5 rounded-lg border border-blue-200 bg-white p-4 text-sm font-bold leading-7 text-blue-900">
                            この管理者ユーザーはDB上の管理者権限で利用しているため、Stripe上の解約対象はありません。
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
