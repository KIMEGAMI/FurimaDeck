<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-blue-400">出品編集</h2>
                <p class="mt-1 text-sm text-cyan-200">登録済み商品の内容を編集できます。</p>
            </div>

            <a href="{{ route('auction-items.index') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-300">
                一覧へ戻る
            </a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4">
                    <ul class="space-y-1 text-sm font-bold text-black">
                        @foreach ($errors->all() as $error)
                            <li>・{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('auction-items.update', $auctionItem) }}" method="POST" enctype="multipart/form-data" class="rounded-3xl bg-white p-6 shadow-xl">
                @csrf
                @method('PATCH')

                <div class="grid grid-cols-1 gap-5">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">管理ID</label>
                            <input type="text" name="management_id" value="{{ old('management_id', $auctionItem->management_id) }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>

                        <div>
                            <label for="platform" class="block text-xs font-black tracking-wider text-slate-600">出品先</label>
                            <select name="platform" id="platform" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach ($platforms as $platformName)
                                    <option value="{{ $platformName }}" @selected(old('platform', $auctionItem->platform) === $platformName)>{{ $platformName }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @include('auction_items.partials.category-selects-v2', [
                        'parentCategories' => $parentCategories,
                        'parentSelectId' => 'edit_parent_category_id',
                        'categorySelectId' => 'edit_category_id',
                        'selectedParentId' => old('parent_category_id', optional($auctionItem->category?->parent)->id),
                        'selectedCategoryId' => old('category_id', $auctionItem->category_id),
                    ])

                    <div>
                        <label class="block text-xs font-black tracking-wider text-slate-600">商品タイトル</label>
                        <input type="text" name="title" value="{{ old('title', $auctionItem->title) }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-xs font-black tracking-wider text-slate-600">コメント</label>
                        <textarea name="comment" rows="4" class="mt-2 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('comment', $auctionItem->comment) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">仕入れ値</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="purchase_price" value="{{ old('purchase_price', $auctionItem->purchase_price) }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">売値</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="sold_price" value="{{ old('sold_price', $auctionItem->sold_price) }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                        <div>
                            <label for="sales_fee_rate" class="block text-xs font-black tracking-wider text-slate-600">販売手数料率 (%)</label>
                            <input type="number" step="0.1" name="sales_fee_rate" id="sales_fee_rate" value="{{ old('sales_fee_rate', $auctionItem->sales_fee_rate ?? 10) }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs font-semibold text-slate-500">出品先を選ぶと自動で反映されます。</p>
                        </div>

                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">送料</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="shipping_fee" value="{{ old('shipping_fee', $auctionItem->shipping_fee ?? 0) }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="rounded-2xl bg-slate-100 p-4">
                            <p class="text-xs font-black tracking-wider text-slate-500">利益計算式</p>
                            <p class="mt-2 text-sm font-black text-slate-700">売値 - 仕入れ値 - 手数料 - 送料</p>
                        </div>
                    </div>

                    @include('auction_items.partials.profit-tools')

                    <div class="order-first">
                        <span class="block text-xs font-black tracking-wider text-slate-600">商品画像</span>
                        <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="camera_image" class="block text-sm font-black text-slate-700">カメラで撮影</label>
                                    <input id="camera_image" type="file" name="camera_image" accept="image/jpeg,image/png,image/webp" capture="environment" class="mt-2 block w-full cursor-pointer rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-700 file:px-4 file:py-2 file:text-sm file:font-black file:text-white">
                                </div>

                                <div>
                                    <label for="image" class="block text-sm font-black text-slate-700">画像を選択</label>
                                    <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full cursor-pointer rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-black file:text-slate-700">
                                </div>
                            </div>
                            <p class="mt-2 text-xs font-semibold text-slate-500">スマホでは「カメラで撮影」から背面カメラを起動できます。JPG / PNG / WEBP 対応。最大2MB。</p>

                            <div id="image-preview-wrap" class="mt-4 hidden">
                                <p class="mb-2 text-xs font-black tracking-wider text-slate-600">選択中の画像</p>
                                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    <img id="image-preview" src="" alt="選択中の商品画像" class="h-72 w-full object-contain">
                                </div>
                            </div>

                            @if($auctionItem->image_path)
                                <div class="mt-5">
                                    <p class="mb-3 text-xs font-black tracking-wider text-slate-600">現在の画像</p>
                                    <img src="{{ asset('storage/' . $auctionItem->image_path) }}" alt="{{ $auctionItem->title }}" class="h-40 w-40 rounded-2xl border border-slate-200 object-cover shadow">
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-6 py-3 text-sm font-black text-white shadow transition hover:bg-blue-800">
                            更新する
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const feeRates = @json($salesFeeRates);
            const platform = document.getElementById('platform');
            const salesFeeRate = document.getElementById('sales_fee_rate');
            const imageInput = document.getElementById('image');
            const cameraImageInput = document.getElementById('camera_image');
            const imagePreviewWrap = document.getElementById('image-preview-wrap');
            const imagePreview = document.getElementById('image-preview');
            let previewUrl = null;

            platform?.addEventListener('change', function () {
                if (Object.prototype.hasOwnProperty.call(feeRates, platform.value)) {
                    salesFeeRate.value = feeRates[platform.value];
                }
            });

            function showImagePreview(file) {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = null;
                }

                if (!file) {
                    imagePreview.removeAttribute('src');
                    imagePreviewWrap.classList.add('hidden');
                    return;
                }

                previewUrl = URL.createObjectURL(file);
                imagePreview.src = previewUrl;
                imagePreviewWrap.classList.remove('hidden');
            }

            imageInput?.addEventListener('change', function () {
                if (imageInput.files?.length) {
                    cameraImageInput.value = '';
                }

                showImagePreview(imageInput.files?.[0]);
            });

            cameraImageInput?.addEventListener('change', function () {
                if (cameraImageInput.files?.length) {
                    imageInput.value = '';
                }

                showImagePreview(cameraImageInput.files?.[0]);
            });
        });
    </script>
</x-app-layout>
