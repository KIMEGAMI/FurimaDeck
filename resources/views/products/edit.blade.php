<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">商品編集</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4">
            @if(session('success'))<p class="mb-4 rounded bg-emerald-50 p-3 font-bold text-emerald-800">{{ session('success') }}</p>@endif
            @if(session('error'))<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ session('error') }}</p>@endif
            @if($errors->any())<p class="mb-4 rounded border border-red-300 bg-red-50 p-3 font-bold text-red-900">{{ $errors->first() }}</p>@endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('products.update', $product) }}" class="rounded-lg bg-white p-6 shadow">
                @csrf @method('PUT')
                <div class="{{ config('chrome_extension.listing_assist_enabled', false) ? 'grid items-start gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]' : '' }}">
                    @if (config('chrome_extension.listing_assist_enabled', false))
                    <aside class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="marketplace-shortcuts-heading">
                        <h3 id="marketplace-shortcuts-heading" class="text-base font-black text-slate-900">出品先</h3>
                        <p class="mt-1 text-sm font-semibold text-slate-700">選んだフリマの出品画面を開きます。拡張機能と連携し、サイトごとの任意権限を許可済みの場合は、登録済み情報のうち一意に判定できた空欄へ入力を試みます。</p>
                        <nav class="mt-4 grid gap-2" aria-label="フリマ出品画面">
                            @foreach (config('chrome_extension.platforms') as $platformId => $platform)
                                @if (($platform['enabled'] ?? false) && !empty($platform['listing_url']))
                                    <a href="{{ $platform['listing_url'] }}" target="_blank" rel="noopener noreferrer" class="block rounded-lg border border-cyan-800 bg-cyan-800 px-3 py-2.5 text-center text-sm font-black text-white hover:bg-cyan-900 focus:outline-none focus:ring-2 focus:ring-cyan-700" data-marketplace-shortcut="{{ $platformId }}" data-product-id="{{ $product->id }}">{{ $platform['name'] }}の出品画面を開く ↗</a>
                                @endif
                            @endforeach
                        </nav>
                        <p class="mt-3 text-xs font-semibold leading-5 text-slate-700">既入力・曖昧な項目は変更しません。実画面の項目対応は未検証です。各社の許可は未確認で、ラクマは公式サポートからツール入力を控えるよう案内されています。入力後の確認・出品確定はご自身で行ってください。</p>
                    </aside>
                    @endif
                    <div class="min-w-0">
                        @include('products.partials.form')
                        <button class="mt-6 rounded bg-cyan-700 px-5 py-3 font-black text-white hover:bg-cyan-800">更新する</button>
                    </div>
                </div>
            </form>
            <section class="mt-6 rounded-xl border border-cyan-200 bg-white p-6 shadow">
                <h3 class="text-lg font-black text-slate-900">出品先・出品価格</h3>
                <p class="mt-1 text-sm text-slate-700">商品に登録済みの出品情報を更新できます。ここで変わるのはFurimaDeck内の管理情報で、外部フリマサイトの掲載内容は各サイトでも変更が必要です。</p>
                @if($product->listings->isEmpty())
                    <div class="mt-4 rounded-lg bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-700">出品情報を登録すると、各項目の横から個別にコピーできます。</p>
                        <a href="{{ route('listings.create', ['product_id' => $product->id]) }}" class="mt-3 inline-flex rounded-lg bg-cyan-800 px-4 py-2 text-sm font-black text-white hover:bg-cyan-900">この商品で出品情報を登録</a>
                    </div>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach($product->listings as $listing)
                            <form method="POST" action="{{ route('listings.marketplace-price', $listing) }}" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                @csrf
                                @method('PATCH')
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <p class="font-bold text-slate-900">{{ $listing->listing_title }}</p>
                                        <a href="{{ route('listings.edit', $listing) }}" class="rounded-lg border border-cyan-800 bg-white px-3 py-1.5 text-sm font-bold text-cyan-900 hover:bg-cyan-50" target="_blank" rel="noopener noreferrer">出品情報・個別コピー</a>
                                    </div>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-700">{{ \App\Models\Listing::statusLabel($listing->status) }}</span>
                                </div>
                                @if(in_array($listing->status, ['sold', 'ended', 'cancelled'], true))
                                    <p class="text-sm font-semibold text-slate-700">終了済みの出品情報は、販売・出品履歴を保護するため変更できません。</p>
                                    <p class="mt-1 text-sm text-slate-700">{{ $listing->marketplace?->name ?? '不明な販売先' }} / {{ $listing->listing_price === null ? '価格未設定' : '¥'.number_format($listing->listing_price) }}</p>
                                @else
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <label class="block text-sm font-bold text-slate-800">販売先
                                            <select required name="marketplace_id" data-marketplace-select class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900">
                                                @if($listing->marketplace && !$listing->marketplace->is_active)
                                                    <option value="{{ $listing->marketplace_id }}" data-fee-rate="{{ $listing->expected_fee_rate ?? 0 }}" selected>{{ $listing->marketplace->name }}（現在の販売先・停止中）</option>
                                                @endif
                                                @foreach($marketplaces as $marketplace)
                                                    <option value="{{ $marketplace->id }}" data-fee-rate="{{ $marketplaceFeeRates[(string) $marketplace->id] ?? 0 }}" @selected((string) old('marketplace_id', $listing->marketplace_id) === (string) $marketplace->id)>{{ $marketplace->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-sm font-bold text-slate-800">出品価格
                                            <div class="mt-1 flex items-center gap-2"><span class="text-slate-700">¥</span><input min="0" type="number" name="listing_price" value="{{ old('listing_price', $listing->listing_price) }}" class="w-full rounded border-slate-300 bg-white text-slate-900"></div>
                                        </label>
                                        <label class="block text-sm font-bold text-slate-800">販売手数料率
                                            <div class="mt-1 flex items-center gap-2"><input min="0" max="100" step="0.01" type="number" name="expected_fee_rate" data-fee-rate-input value="{{ old('expected_fee_rate', (float) ($listing->expected_fee_rate ?? 0) > 0 ? $listing->expected_fee_rate : ($marketplaceFeeRates[(string) $listing->marketplace_id] ?? 0)) }}" class="w-full rounded border-slate-300 bg-white text-slate-900"><span class="text-slate-700">%</span></div>
                                            <span class="mt-1 block text-xs font-normal text-slate-600">販売先を変更すると標準手数料率が入ります。必要なら調整できます。</span>
                                        </label>
                                        <label class="block text-sm font-bold text-slate-800">送料
                                            <div class="mt-1 flex items-center gap-2"><span class="text-slate-700">¥</span><input min="0" type="number" name="shipping_fee" data-shipping-fee-input value="{{ old('shipping_fee', $listing->shipping_fee ?? 0) }}" class="w-full rounded border-slate-300 bg-white text-slate-900"></div>
                                        </label>
                                        @if($marketplaces->contains('code', 'other'))
                                            <label class="block text-sm font-bold text-slate-800 md:col-span-2">販売先が「その他」の場合の名称
                                                <input name="marketplace_other_name" value="{{ old('marketplace_other_name', $listing->marketplace_other_name) }}" maxlength="255" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900">
                                            </label>
                                        @endif
                                    </div>
                                    @error('marketplace_id')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                                    @error('listing_price')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                                    @error('expected_fee_rate')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                                    @error('shipping_fee')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                                    @error('marketplace_other_name')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                                    <button class="mt-4 rounded bg-cyan-700 px-4 py-2 font-bold text-white hover:bg-cyan-800">この出品情報を更新</button>
                                @endif
                            </form>
                        @endforeach
                    </div>
                @endif
            </section>
            <script>
                document.querySelectorAll('[data-delete-image-url]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        if (!window.confirm('この画像を削除しますか？')) return;
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = button.dataset.deleteImageUrl;
                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_token';
                        csrfInput.value = document.querySelector('input[name="_token"]').value;
                        form.append(csrfInput);
                        const methodInput = document.createElement('input');
                        methodInput.type = 'hidden';
                        methodInput.name = '_method';
                        methodInput.value = 'DELETE';
                        form.append(methodInput);
                        document.body.append(form);
                        form.submit();
                    });
                });
            </script>
            <form method="POST" action="{{ route('products.destroy', $product) }}" class="mt-6 border-t border-red-200 pt-6">
                @csrf @method('DELETE')
                <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_deletion" value="1" type="checkbox" required class="mt-1">この商品と登録済み画像を削除することを確認しました。</label>
                @error('confirm_deletion')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                <button class="mt-4 rounded bg-red-700 px-4 py-2 font-bold text-white hover:bg-red-800">商品を削除</button>
            </form>
        </div>
    </div>
</x-app-layout>
