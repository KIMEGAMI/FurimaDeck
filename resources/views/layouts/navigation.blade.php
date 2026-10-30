@php
    $user = Auth::user();
    $isAdmin = $user?->isAdmin() ?? false;
    $hasPremiumPlan = $user?->hasActiveSubscription() ?? false;
    $isFurimaDeckCutover = (bool) config('furimadeck.cutover_enabled');
    $brandHref = $isFurimaDeckCutover ? route('furimadeck-dashboard') : ($isAdmin ? route('profile.edit') : route('dashboard'));
    $isProductMenuActive = request()->routeIs('products.*', 'suppliers.*');
    $isCsvMenuActive = request()->routeIs('products.imports.*');
    $isSalesMenuActive = request()->routeIs('furimadeck-sales.*', 'furimadeck-analytics.*');
    $isAccountingMenuActive = request()->routeIs('furimadeck-accounting.*');
    $requestedAccountingSection = request()->query('section', 'overview');
    $accountingSection = request()->routeIs('furimadeck-accounting.freee.*', 'furimadeck-accounting.money-forward.*', 'furimadeck-accounting.connect', 'furimadeck-accounting.callback')
        ? 'connections'
        : (in_array($requestedAccountingSection, ['overview', 'review', 'send', 'csv', 'connections'], true) ? $requestedAccountingSection : 'overview');
    $accountingMenuGroups = [
        ['label' => '1. 取引データを確認', 'sections' => [
            'overview' => ['label' => '準備状況', 'description' => '不足項目と期間別の集計を見る'],
            'review' => ['label' => '明細の確認・確定', 'description' => '金額と領収書などを確認する'],
        ]],
        ['label' => '2. 会計サービスへ登録', 'sections' => [
            'send' => ['label' => 'CSV取込状況', 'description' => '会計ソフトでの登録確認を記録する'],
            'csv' => ['label' => 'CSVで登録', 'description' => '取込用CSVを出力して手動登録'],
        ]],
        ['label' => '3. サービス設定', 'sections' => [
            'connections' => ['label' => '会計ソフト設定', 'description' => 'CSV用の勘定科目・税区分を設定する'],
        ]],
    ];
    $isAccountMenuActive = request()->routeIs('furimadeck-billing.*', 'furimadeck-account.*', 'furimadeck-activity.*');
@endphp

<nav data-responsive-nav class="relative z-50 border-b border-cyan-300/20 bg-slate-950/45 text-white shadow-2xl backdrop-blur-md">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex min-w-0">
                <div class="flex shrink-0 items-center">
                    <a href="{{ $brandHref }}" class="text-xl font-black text-white">
                        FurimaDeck
                    </a>
                </div>

                <div class="hidden space-x-6 lg:-my-px lg:ms-8 lg:flex">
                    @if ($isFurimaDeckCutover)
                        <x-nav-link :href="route('furimadeck-dashboard')" :active="request()->routeIs('furimadeck-dashboard')">
                            HOME
                        </x-nav-link>

                        <details class="group relative">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isProductMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">商品管理 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-48 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">商品管理</p>
                                <a href="{{ route('products.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">商品一覧</a>
                                <a href="{{ route('products.create') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">商品を登録</a>
                                <a href="{{ route('suppliers.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">仕入先</a>
                            </div>
                        </details>
                        <x-nav-link :href="route('products.imports.create')" :active="$isCsvMenuActive">
                            CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                        </x-nav-link>

                        <details class="group relative">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isSalesMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">分析 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-52 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">分析</p>
<a href="{{ route('furimadeck-analytics.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">売上分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                                <a href="{{ route('furimadeck-analytics.advanced') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">高度分析</a>
                                <a href="{{ route('furimadeck-improvement.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">販売改善</a>
                                <a href="{{ route('furimadeck-analytics.categories') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">ジャンル別分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                                <a href="{{ route('furimadeck-analytics.cross') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">クロス分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                            </div>
                        </details>

                        <details class="group relative">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isAccountingMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">会計データ <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 max-h-[70vh] min-w-72 overflow-y-auto rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                @foreach($accountingMenuGroups as $group)
                                    <p class="px-3 pb-1 pt-2 text-xs font-black text-cyan-200">{{ $group['label'] }}</p>
                                    @foreach($group['sections'] as $section => $item)
                                        <a href="{{ route('furimadeck-accounting.index', array_merge(request()->only(['year', 'from', 'to']), ['section' => $section])) }}" @class(['block rounded px-3 py-2', 'bg-slate-800 text-white' => $accountingSection === $section, 'text-slate-100 hover:bg-slate-800' => $accountingSection !== $section]) aria-current="{{ $accountingSection === $section ? 'page' : 'false' }}"><span class="block text-sm font-bold">{{ $item['label'] }}</span><span class="mt-0.5 block text-xs text-slate-300">{{ $item['description'] }}</span></a>
                                    @endforeach
                                @endforeach
                            </div>
                        </details>

                        <details class="group relative">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isAccountMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">アカウント <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-44 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">アカウント</p>
                                <a href="{{ route('furimadeck-billing.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">契約・解約</a>
                                <a href="{{ route('furimadeck-account.edit') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">アカウント設定</a>
                                <a href="{{ route('furimadeck-activity.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">操作履歴</a>
                            </div>
                        </details>

                        @if ($isAdmin)
                            <details class="group relative">
                                <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-amber-200 marker:hidden hover:text-white {{ request()->routeIs('admin.*', 'notices.*') ? 'border-amber-300 text-white' : 'border-transparent' }}">管理者 <span aria-hidden="true">⌄</span></summary>
                                <div class="absolute left-0 top-full z-50 w-52 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                    <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-amber-200">管理者メニュー</p>
                                    <a href="{{ route('admin.maintenance.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">管理者画面</a>
                                    <a href="{{ route('admin.users.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">ユーザー一覧</a>
                                    <a href="{{ route('admin.bulk-mail.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">一斉メール送信</a>
                                    <a href="{{ route('admin.growth.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">成長管理</a>
                                    <a href="{{ route('notices.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">お知らせ一覧</a>
                                </div>
                            </details>
                        @endif

                        <x-nav-link :href="route('furimadeck-manual')" :active="request()->routeIs('furimadeck-manual')">
                            使い方
                        </x-nav-link>

                    @elseif ($isAdmin)
                        <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
                            プロフィール
                        </x-nav-link>

                        <x-nav-link :href="route('subscriptions.index')" :active="request()->routeIs('subscriptions.*')">
                            契約・解約
                        </x-nav-link>

                        <x-nav-link :href="route('admin.maintenance.index')" :active="request()->routeIs('admin.maintenance.*', 'admin.notices.*')">
                            管理者画面
                        </x-nav-link>

                        <x-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                            ユーザー一覧
                        </x-nav-link>

                        <x-nav-link :href="route('admin.bulk-mail.index')" :active="request()->routeIs('admin.bulk-mail.*')">
                            一斉メール送信
                        </x-nav-link>

                        <x-nav-link :href="route('admin.growth.index')" :active="request()->routeIs('admin.growth.*')">
                            成長管理
                        </x-nav-link>

                        <x-nav-link :href="route('notices.index')" :active="request()->routeIs('notices.*')">
                            お知らせ一覧
                        </x-nav-link>
                    @else
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            HOME
                        </x-nav-link>

                        <x-nav-link :href="route('auction-items.index')" :active="request()->routeIs('auction-items.index', 'auction-items.show', 'auction-items.edit', 'auction-items.duplicates')">
                            商品一覧
                        </x-nav-link>

                        <x-nav-link :href="route('auction-items.create')" :active="request()->routeIs('auction-items.create')">
                            商品登録
                        </x-nav-link>

                        <x-nav-link :href="route('auction-items.csv-import')" :active="request()->routeIs('auction-items.csv-import')">
                            CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                        </x-nav-link>

                        <x-nav-link :href="route('sales.index')" :active="request()->routeIs('sales.*')">
                            売上管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                        </x-nav-link>

                        <x-nav-link :href="route('category-sales.index')" :active="request()->routeIs('category-sales.*')">
                            ジャンル別売上{{ $hasPremiumPlan ? '' : ' Premium' }}
                        </x-nav-link>

                        <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
                            プロフィール
                        </x-nav-link>

                        <x-nav-link :href="route('subscriptions.index')" :active="request()->routeIs('subscriptions.*')">
                            契約・解約
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden lg:ms-6 lg:flex lg:items-center">
                <div class="text-sm font-bold text-slate-200">
                    {{ $user->name }}
                </div>

                <form method="POST" action="{{ route('logout') }}" class="ms-6">
                    @csrf

                    <button
                        type="submit"
                        class="rounded-lg bg-red-500 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600"
                    >
                        ログアウト
                    </button>
                </form>
            </div>

            <div class="-me-2 flex items-center lg:hidden">
                <button
                    type="button"
                    data-nav-toggle
                    class="inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-black text-slate-100 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white focus:outline-none"
                    aria-label="メニューを開く"
                    aria-expanded="false"
                    aria-controls="responsive-navigation-menu"
                >
                    <span>メニュー</span>
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path
                            data-nav-icon
                            class="inline-flex"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />

                        <path
                            data-nav-close-icon
                            class="hidden"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div id="responsive-navigation-menu" data-nav-menu class="hidden lg:hidden">
        <div class="space-y-1 pb-3 pt-2">
            @if ($isFurimaDeckCutover)
                <x-responsive-nav-link :href="route('furimadeck-dashboard')" :active="request()->routeIs('furimadeck-dashboard')">
                    HOME
                </x-responsive-nav-link>

                <section class="border-b border-cyan-300/10 py-2" aria-label="商品管理">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">商品管理</p>
                    <x-responsive-nav-link :href="route('products.index')" :active="request()->routeIs('products.index')">商品一覧</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('products.create')" :active="request()->routeIs('products.create')">商品を登録</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')">仕入先</x-responsive-nav-link>
                </section>
                <x-responsive-nav-link :href="route('products.imports.create')" :active="$isCsvMenuActive">
                    CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                </x-responsive-nav-link>

                <section class="border-b border-cyan-300/10 py-2" aria-label="分析">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">分析</p>
<x-responsive-nav-link :href="route('furimadeck-analytics.index')" :active="request()->routeIs('furimadeck-analytics.index')">売上分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.advanced')" :active="request()->routeIs('furimadeck-analytics.advanced')">高度分析</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-improvement.index')" :active="request()->routeIs('furimadeck-improvement.*')">販売改善</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.categories')" :active="request()->routeIs('furimadeck-analytics.categories')">ジャンル別分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.cross')" :active="request()->routeIs('furimadeck-analytics.cross')">クロス分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                </section>
                <section class="border-b border-cyan-300/10 py-2" aria-label="会計データ">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">会計データ</p>
                    @foreach($accountingMenuGroups as $group)
                        <p class="px-4 pb-1 pt-2 text-xs font-black text-cyan-200">{{ $group['label'] }}</p>
                        @foreach($group['sections'] as $section => $item)
                            <x-responsive-nav-link :href="route('furimadeck-accounting.index', array_merge(request()->only(['year', 'from', 'to']), ['section' => $section]))" :active="$accountingSection === $section"><span class="block">{{ $item['label'] }}</span><span class="block text-xs font-normal text-slate-300">{{ $item['description'] }}</span></x-responsive-nav-link>
                        @endforeach
                    @endforeach
                </section>

                <section class="border-b border-cyan-300/10 py-2" aria-label="アカウント">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">アカウント</p>
                    <x-responsive-nav-link :href="route('furimadeck-billing.index')" :active="request()->routeIs('furimadeck-billing.*')">契約・解約</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-account.edit')" :active="request()->routeIs('furimadeck-account.*')">アカウント設定</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-activity.index')" :active="request()->routeIs('furimadeck-activity.*')">操作履歴</x-responsive-nav-link>
                </section>

                @if ($isAdmin)
                    <section class="border-b border-amber-300/20 py-2" aria-label="管理者メニュー">
                        <p class="px-4 py-1 text-xs font-black tracking-wide text-amber-200">管理者メニュー</p>
                        <x-responsive-nav-link :href="route('admin.maintenance.index')" :active="request()->routeIs('admin.maintenance.*', 'admin.notices.*')">管理者画面</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">ユーザー一覧</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('admin.bulk-mail.index')" :active="request()->routeIs('admin.bulk-mail.*')">一斉メール送信</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('admin.growth.index')" :active="request()->routeIs('admin.growth.*')">成長管理</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('notices.index')" :active="request()->routeIs('notices.*')">お知らせ一覧</x-responsive-nav-link>
                    </section>
                @endif

                <x-responsive-nav-link :href="route('furimadeck-manual')" :active="request()->routeIs('furimadeck-manual')">
                    使い方
                </x-responsive-nav-link>

            @elseif ($isAdmin)
                <x-responsive-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
                    プロフィール
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('subscriptions.index')" :active="request()->routeIs('subscriptions.*')">
                    契約・解約
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('admin.maintenance.index')" :active="request()->routeIs('admin.maintenance.*', 'admin.notices.*')">
                    管理者画面
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                    ユーザー一覧
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('admin.bulk-mail.index')" :active="request()->routeIs('admin.bulk-mail.*')">
                    一斉メール送信
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('admin.growth.index')" :active="request()->routeIs('admin.growth.*')">
                    成長管理
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('notices.index')" :active="request()->routeIs('notices.*')">
                    お知らせ一覧
                </x-responsive-nav-link>
            @else
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    HOME
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('auction-items.index')" :active="request()->routeIs('auction-items.index', 'auction-items.show', 'auction-items.edit', 'auction-items.duplicates')">
                    商品一覧
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('auction-items.create')" :active="request()->routeIs('auction-items.create')">
                    商品登録
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('auction-items.csv-import')" :active="request()->routeIs('auction-items.csv-import')">
                    CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('sales.index')" :active="request()->routeIs('sales.*')">
                    売上管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('category-sales.index')" :active="request()->routeIs('category-sales.*')">
                    ジャンル別売上{{ $hasPremiumPlan ? '' : ' Premium' }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
                    プロフィール
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('subscriptions.index')" :active="request()->routeIs('subscriptions.*')">
                    契約・解約
                </x-responsive-nav-link>
            @endif
        </div>

        <div class="border-t border-cyan-300/20 px-4 py-4">
            <x-pwa-install-options />
        </div>

        <div class="border-t border-cyan-300/20 pb-1 pt-4">
            <div class="px-4">
                <div class="text-base font-medium text-white">
                    {{ $user->name }}
                </div>

                <div class="text-sm font-medium text-slate-300">
                    {{ $user->email }}
                </div>
            </div>

            <div class="mt-3 space-y-1">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button type="submit" class="block w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-base font-bold text-slate-100 transition duration-150 ease-in-out hover:border-emerald-300 hover:bg-white/10 hover:text-white focus:border-emerald-300 focus:bg-white/10 focus:text-white focus:outline-none">
                        ログアウト
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
