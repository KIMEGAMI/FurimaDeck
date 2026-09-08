@php
    $user = Auth::user();
    $isAdmin = $user?->isAdmin() ?? false;
    $hasPremiumPlan = $user?->hasActiveSubscription() ?? false;
    $brandHref = $isAdmin ? route('profile.edit') : route('dashboard');
@endphp

<nav x-data="{ open: false }" class="border-b border-cyan-300/20 bg-slate-950/45 text-white shadow-2xl backdrop-blur-md">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex min-w-0">
                <div class="flex shrink-0 items-center">
                    <a href="{{ $brandHref }}" class="text-xl font-black text-white">
                        FurimaDeck
                    </a>
                </div>

                <div class="hidden space-x-6 sm:-my-px sm:ms-8 sm:flex">
                    @if ($isAdmin)
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
                    class="inline-flex items-center justify-center rounded-md p-2 text-slate-100 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white focus:outline-none"
                    aria-label="メニューを開く"
                >
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
            @if ($isAdmin)
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
