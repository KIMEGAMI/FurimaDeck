@php
    $user = Auth::user();
    $isAdmin = $user?->isAdmin() ?? false;
    $hasPremiumPlan = $user?->hasActiveSubscription() ?? false;
    $isFurimaDeckCutover = (bool) config('furimadeck.cutover_enabled');
    $brandHref = $isFurimaDeckCutover ? route('furimadeck-dashboard') : ($isAdmin ? route('profile.edit') : route('dashboard'));
    $isProductMenuActive = request()->routeIs('products.*');
    $isCsvMenuActive = request()->routeIs('products.imports.*');
    $isSalesMenuActive = request()->routeIs('furimadeck-sales.*', 'furimadeck-analytics.*');
    $isAccountMenuActive = request()->routeIs('furimadeck-billing.*', 'furimadeck-account.*', 'furimadeck-activity.*', 'furimadeck-accounting.*');
@endphp

<nav x-data="{ open: false }" class="relative z-50 border-b border-cyan-300/20 bg-slate-950/45 text-white shadow-2xl backdrop-blur-md">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex min-w-0">
                <div class="flex shrink-0 items-center">
                    <a href="{{ $brandHref }}" class="text-xl font-black text-white">
                        FurimaDeck
                    </a>
                </div>

                <div class="hidden space-x-6 sm:-my-px sm:ms-8 sm:flex">
                    @if ($isFurimaDeckCutover)
                        <x-nav-link :href="route('furimadeck-dashboard')" :active="request()->routeIs('furimadeck-dashboard')">
                            HOME
                        </x-nav-link>

                        <details class="group relative" @mouseleave="$el.open = false" @keydown.escape="$el.open = false">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isProductMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">商品管理 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-48 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">商品管理</p>
                                <a href="{{ route('products.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">商品一覧</a>
                                <a href="{{ route('products.create') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">商品を登録</a>
                            </div>
                        </details>
<x-nav-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')">
                            仕入先
                        </x-nav-link>
                        <x-nav-link :href="route('products.imports.create')" :active="$isCsvMenuActive">
                            CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                        </x-nav-link>

                        <details class="group relative" @mouseleave="$el.open = false" @keydown.escape="$el.open = false">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isSalesMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">分析 <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-52 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">分析</p>
<a href="{{ route('furimadeck-analytics.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">売上分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                                <a href="{{ route('furimadeck-analytics.advanced') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">高度分析</a>
                                <a href="{{ route('furimadeck-analytics.suitability') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">出品先適性</a>
                                <a href="{{ route('furimadeck-improvement.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">販売改善</a>
                                <a href="{{ route('furimadeck-analytics.categories') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">ジャンル別分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                                <a href="{{ route('furimadeck-analytics.cross') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">クロス分析{{ $hasPremiumPlan ? '' : ' Premium' }}</a>
                            </div>
                        </details>

                        <details class="group relative" @mouseleave="$el.open = false" @keydown.escape="$el.open = false">
                            <summary class="cursor-pointer list-none border-b-2 px-1 pb-1 pt-6 text-sm font-bold leading-5 text-slate-300 marker:hidden hover:text-white {{ $isAccountMenuActive ? 'border-cyan-300 text-white' : 'border-transparent' }}">アカウント <span aria-hidden="true">⌄</span></summary>
                            <div class="absolute left-0 top-full z-50 w-44 rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-xl">
                                <p class="px-3 pb-2 pt-1 text-xs font-black tracking-wide text-cyan-200">アカウント</p>
                                <a href="{{ route('furimadeck-billing.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">契約・解約</a>
                                <a href="{{ route('furimadeck-accounting.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">確定申告・会計</a>
                                <a href="{{ route('furimadeck-account.edit') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">アカウント設定</a>
                                <a href="{{ route('furimadeck-activity.index') }}" class="block rounded px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-800">操作履歴</a>
                            </div>
                        </details>

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

            <div class="hidden sm:ms-6 sm:flex sm:items-center">
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

            <div class="-me-2 flex items-center sm:hidden">
                <button
                    @click="open = ! open"
                    class="inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-black text-slate-100 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white focus:outline-none"
                    aria-label="メニューを開く"
                >
                    <span>メニュー</span>
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path
                            :class="{'hidden': open, 'inline-flex': ! open }"
                            class="inline-flex"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />

                        <path
                            :class="{'hidden': ! open, 'inline-flex': open }"
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

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="space-y-1 pb-3 pt-2">
            @if ($isFurimaDeckCutover)
                <x-responsive-nav-link :href="route('furimadeck-dashboard')" :active="request()->routeIs('furimadeck-dashboard')">
                    HOME
                </x-responsive-nav-link>

                <section class="border-b border-cyan-300/10 py-2" aria-label="商品管理">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">商品管理</p>
                    <x-responsive-nav-link :href="route('products.index')" :active="request()->routeIs('products.index')">商品一覧</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('products.create')" :active="request()->routeIs('products.create')">商品を登録</x-responsive-nav-link>
                </section>
<x-responsive-nav-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')">
                    仕入先
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('products.imports.create')" :active="$isCsvMenuActive">
                    CSV管理{{ $hasPremiumPlan ? '' : ' Premium' }}
                </x-responsive-nav-link>

                <section class="border-b border-cyan-300/10 py-2" aria-label="分析">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">分析</p>
<x-responsive-nav-link :href="route('furimadeck-analytics.index')" :active="request()->routeIs('furimadeck-analytics.index')">売上分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.advanced')" :active="request()->routeIs('furimadeck-analytics.advanced')">高度分析</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.suitability')" :active="request()->routeIs('furimadeck-analytics.suitability')">出品先適性</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-improvement.index')" :active="request()->routeIs('furimadeck-improvement.*')">販売改善</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.categories')" :active="request()->routeIs('furimadeck-analytics.categories')">ジャンル別分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-analytics.cross')" :active="request()->routeIs('furimadeck-analytics.cross')">クロス分析{{ $hasPremiumPlan ? '' : ' Premium' }}</x-responsive-nav-link>
                </section>
                <section class="border-b border-cyan-300/10 py-2" aria-label="アカウント">
                    <p class="px-4 py-1 text-xs font-black tracking-wide text-cyan-200">アカウント</p>
                    <x-responsive-nav-link :href="route('furimadeck-billing.index')" :active="request()->routeIs('furimadeck-billing.*')">契約・解約</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-accounting.index')" :active="request()->routeIs('furimadeck-accounting.*')">確定申告・会計</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-account.edit')" :active="request()->routeIs('furimadeck-account.*')">アカウント設定</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('furimadeck-activity.index')" :active="request()->routeIs('furimadeck-activity.*')">操作履歴</x-responsive-nav-link>
                </section>

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

                    <x-responsive-nav-link :href="route('logout')"
                                           onclick="event.preventDefault(); this.closest('form').submit();">
                        ログアウト
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
