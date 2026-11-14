<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">商品詳細・編集</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4">
            @if(session('success'))<p class="mb-4 rounded bg-emerald-50 p-3 font-bold text-emerald-800">{{ session('success') }}</p>@endif
            @if(session('error'))<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ session('error') }}</p>@endif
            @if(session('warning'))<p class="mb-4 rounded border border-amber-400 bg-amber-50 p-3 font-bold text-amber-950">{{ session('warning') }}</p>@endif
            @if($errors->any())<p class="mb-4 rounded border border-red-300 bg-red-50 p-3 font-bold text-red-900">{{ $errors->first() }}</p>@endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('products.update', $product) }}" class="rounded-lg bg-white p-6 shadow">
                @csrf @method('PUT')
                <div>
                    @include('products.partials.form')
                    <button class="mt-6 rounded bg-cyan-700 px-5 py-3 font-black text-white hover:bg-cyan-800">更新する</button>
                </div>
            </form>
            <details class="mt-6 rounded-lg border border-slate-300 bg-white p-6 shadow">
                <summary class="cursor-pointer text-lg font-black text-slate-950">仕入・在庫履歴（{{ $inventoryEvents->count() }}件）</summary>
                <p class="mt-2 text-sm font-semibold text-slate-700">仕入情報と在庫の変更記録です。仕入情報のスナップショットは暗号化して保存しています。</p>
                @if ($inventoryEvents->isEmpty())
                    <p class="mt-4 rounded border border-slate-300 bg-slate-50 p-3 text-sm font-semibold text-slate-800">履歴はまだありません。</p>
                @else
                    @if ($inventoryEvents->count() === 50)
                        <p class="mt-3 text-xs font-semibold text-slate-700">新しい履歴を50件表示しています。</p>
                    @endif
                    <ol class="mt-4 space-y-3">
                        @foreach ($inventoryEvents as $event)
                            <li class="rounded border border-slate-300 bg-slate-50 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h4 class="font-black text-slate-950">{{ $event->eventTypeLabel() }}</h4>
                                    <time class="text-sm font-semibold text-slate-700" datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->format('Y/m/d H:i') }}</time>
                                </div>
                                @if ($event->quantity_delta !== 0)
                                    <p class="mt-2 text-sm font-bold text-slate-900">在庫変化：{{ $event->quantity_delta > 0 ? '+' : '' }}{{ $event->quantity_delta }}点</p>
                                @endif
                                @if ($event->event_type === \App\Models\ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED)
                                    @php($beforeState = $event->before_state ?? [])
                                    @php($afterState = $event->after_state ?? [])
                                    @php($beforeSupplier = $beforeState['supplier_name'] ?? (isset($beforeState['supplier_id']) ? '仕入先ID '.$beforeState['supplier_id'] : '未設定'))
                                    @php($afterSupplier = $afterState['supplier_name'] ?? (isset($afterState['supplier_id']) ? '仕入先ID '.$afterState['supplier_id'] : '未設定'))
                                    <dl class="mt-2 grid gap-1 text-sm text-slate-800 sm:grid-cols-2">
                                        <div><dt class="inline font-bold">仕入日：</dt><dd class="inline">{{ $beforeState['purchase_date'] ?? '未設定' }} → {{ $afterState['purchase_date'] ?? '未設定' }}</dd></div>
                                        <div><dt class="inline font-bold">仕入単価：</dt><dd class="inline">{{ number_format((int) ($beforeState['purchase_unit_cost'] ?? 0)) }}円 → {{ number_format((int) ($afterState['purchase_unit_cost'] ?? 0)) }}円</dd></div>
                                        <div><dt class="inline font-bold">仕入送料：</dt><dd class="inline">{{ number_format((int) ($beforeState['purchase_shipping_cost'] ?? 0)) }}円 → {{ number_format((int) ($afterState['purchase_shipping_cost'] ?? 0)) }}円</dd></div>
                                        <div><dt class="inline font-bold">その他仕入費：</dt><dd class="inline">{{ number_format((int) ($beforeState['other_purchase_expense'] ?? 0)) }}円 → {{ number_format((int) ($afterState['other_purchase_expense'] ?? 0)) }}円</dd></div>
                                        <div class="sm:col-span-2"><dt class="inline font-bold">仕入先：</dt><dd class="inline">{{ $beforeSupplier }} → {{ $afterSupplier }}</dd></div>
                                    </dl>
                                @endif
                                @if (filled($event->reason))
                                    <p class="mt-2 text-sm font-semibold text-slate-800">理由：{{ $event->reason }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </details>
            <section class="mt-6 rounded-lg border border-slate-300 bg-white p-6 shadow" aria-labelledby="inventory-adjustment-heading">
                <h3 id="inventory-adjustment-heading" class="text-lg font-black text-slate-950">在庫状態の調整</h3>
                <p class="mt-1 text-sm font-semibold leading-6 text-slate-800">現物の紛失・発見などで実在庫と画面の状態が異なる場合に調整します。理由と変更履歴を保存します。商品IDは現物1点のため、在庫は「在庫あり」か「在庫なし」です。</p>
                @if ($product->listings()->where('status', 'active')->exists())
                    <p class="mt-3 rounded border border-amber-400 bg-amber-50 p-3 text-sm font-bold text-amber-950">FurimaDeckに「出品中」の情報があります。在庫を「在庫なし」にする場合は、各フリマサイトの掲載も確認し、必要なら取り下げてください。この調整は外部サイトの掲載を変更しません。</p>
                @endif
                @if ($product->sales()->exists())
                    <p class="mt-3 rounded border border-amber-400 bg-amber-50 p-3 text-sm font-bold text-amber-950">販売履歴がある商品の在庫はここから変更できません。販売取消または返品の操作を使用してください。</p>
                @else
                    <form method="POST" action="{{ route('products.inventory.adjust', $product) }}" class="mt-4 grid gap-4 md:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="adjustment_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <label class="block text-sm font-bold text-slate-900">調整後の状態
                            <select required name="target_quantity" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-950">
                                <option value="1" @selected((int) old('target_quantity', $product->quantity_available) === 1)>在庫あり</option>
                                <option value="0" @selected((int) old('target_quantity', $product->quantity_available) === 0)>在庫なし</option>
                            </select>
                        </label>
                        <label class="block text-sm font-bold text-slate-900">調整理由
                            <input required minlength="3" maxlength="500" name="reason" value="{{ old('reason') }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-950" placeholder="例：現物を確認したところ破損していた">
                        </label>
                        @error('target_quantity')<p class="text-sm font-bold text-red-800 md:col-span-2">{{ $message }}</p>@enderror
                        @error('reason')<p class="text-sm font-bold text-red-800 md:col-span-2">{{ $message }}</p>@enderror
                        <button class="justify-self-start rounded bg-slate-800 px-4 py-2.5 text-sm font-black text-white hover:bg-slate-950">在庫状態を調整</button>
                    </form>
                @endif
            </section>
            <section class="mt-6 rounded-xl border border-red-300 bg-white p-6 shadow" aria-labelledby="delete-product-heading">
                <h3 id="delete-product-heading" class="text-lg font-black text-red-900">商品を削除</h3>
                <p class="mt-2 text-sm font-semibold leading-6 text-slate-800">削除するとFurimaDeck内の商品情報、出品情報、登録画像が削除されます。外部フリマサイトへの掲載は削除されないため、掲載中の場合は各サイトで先に取り下げてください。</p>
                @if ($canDelete)
                    <form method="POST" action="{{ route('products.destroy', $product) }}" class="mt-4 border-t border-red-200 pt-4">
                        @csrf
                        @method('DELETE')
                        <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_deletion" value="1" type="checkbox" required class="mt-1">この商品と、登録画像およびFurimaDeck内の出品情報を削除することを確認しました。</label>
                        <button type="submit" class="mt-4 rounded-lg bg-red-700 px-4 py-2.5 font-bold text-white hover:bg-red-800">商品を削除</button>
                    </form>
                @else
                    <p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm font-bold leading-6 text-amber-950">販売・会計履歴がある商品は、履歴を保護するため削除できません。CSV登録とは関係ありません。商品編集画面から在庫数を直接変更する機能もありません。</p>
                @endif
            </section>
            <section class="mt-6 rounded-xl border border-cyan-200 bg-white p-6 shadow">
                <h3 class="text-lg font-black text-slate-900">出品先・出品価格</h3>
                <p class="mt-1 text-sm text-slate-700">商品に登録済みの出品情報を更新できます。ここで変わるのはFurimaDeck内の管理情報で、外部フリマサイトの掲載内容は各サイトでも変更が必要です。</p>
                @if($product->listings->isEmpty())
                    <div class="mt-4 rounded-lg bg-slate-50 p-4">
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
                                        <a href="{{ route('listings.edit', $listing) }}" class="rounded-lg border border-cyan-800 bg-white px-3 py-1.5 text-sm font-bold text-cyan-900 hover:bg-cyan-50" target="_blank" rel="noopener noreferrer">出品情報を編集</a>
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
            <a href="{{ route('products.index') }}" class="mt-6 inline-flex rounded-lg border border-cyan-800 bg-white px-4 py-2 font-bold text-cyan-900 hover:bg-cyan-50">商品一覧へ戻る</a>
        </div>
    </div>
</x-app-layout>
