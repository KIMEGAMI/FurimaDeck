<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-blue-400">出品登録</h2>
                <p class="mt-1 text-sm text-cyan-200">フリマ・オークションの商品情報を登録します。</p>
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
                    <ul class="space-y-1 text-sm font-bold text-red-600">
                        @foreach ($errors->all() as $error)
                            <li>・{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('auction-items.store') }}" method="POST" enctype="multipart/form-data" class="rounded-3xl bg-white p-6 shadow-xl">
                @csrf

                <div class="grid grid-cols-1 gap-5">
                    <div>
                        <span class="block text-xs font-black tracking-wider text-slate-600">商品画像</span>
                        <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <input id="images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="sr-only">
                            <input id="camera-images" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only">

                            <div id="image-drop-zone" class="rounded-xl border-2 border-dashed border-slate-300 bg-white p-6 text-center transition">
                                <p class="text-sm font-black text-slate-700">画像をここへドラッグ＆ドロップ</p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">JPG / PNG / WEBP、1枚あたり2MB、最大10枚</p>
                                <div class="mt-4 flex flex-wrap justify-center gap-2">
                                    <button id="choose-images" type="button" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-black text-white transition hover:bg-slate-700">画像を選択</button>
                                    <button id="take-photo" type="button" class="rounded-lg border border-blue-700 bg-white px-4 py-2 text-sm font-black text-blue-700 transition hover:bg-blue-50">カメラで撮影</button>
                                </div>
                            </div>
                            <p id="image-upload-error" class="mt-3 hidden text-sm font-bold text-red-700" role="alert"></p>
                            <div id="image-preview-wrap" class="mt-4 hidden">
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-xs font-black tracking-wider text-slate-600">選択中の画像</p>
                                    <p id="image-count" class="text-xs font-bold text-slate-500"></p>
                                </div>
                                <div id="image-preview-list" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">管理ID</label>
                            <input type="text" name="management_id" value="{{ old('management_id') }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="FRG-0001">
                        </div>
                        <div>
                            <label for="platform" class="block text-xs font-black tracking-wider text-slate-600">出品先</label>
                            <select name="platform" id="platform" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                @foreach ($platforms as $platformName)
                                    <option value="{{ $platformName }}" @selected(old('platform', $platforms[0]) === $platformName)>{{ $platformName }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @include('auction_items.partials.category-selects-v2', [
                        'parentCategories' => $parentCategories,
                        'parentSelectId' => 'create_parent_category_id',
                        'categorySelectId' => 'create_category_id',
                    ])

                    <div>
                        <label class="block text-xs font-black tracking-wider text-slate-600">商品タイトル</label>
                        <input type="text" name="title" value="{{ old('title') }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="商品名・型番など">
                    </div>

                    <div>
                        <label class="block text-xs font-black tracking-wider text-slate-600">コメント</label>
                        <textarea name="comment" rows="4" class="mt-2 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="サイズ感、状態、特徴など">{{ old('comment') }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">仕入れ値</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="purchase_price" value="{{ old('purchase_price') }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="3000">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">売値</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="sold_price" value="{{ old('sold_price') }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="8500">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                        <div>
                            <label for="sales_fee_rate" class="block text-xs font-black tracking-wider text-slate-600">販売手数料率 (%)</label>
                            <input type="number" step="0.1" name="sales_fee_rate" id="sales_fee_rate" value="{{ old('sales_fee_rate', 10) }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <p class="mt-1 text-xs font-semibold text-slate-500">出品先を選ぶと自動で反映されます。</p>
                        </div>
                        <div>
                            <label class="block text-xs font-black tracking-wider text-slate-600">送料</label>
                            <div class="relative mt-2">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-black text-slate-500">¥</span>
                                <input type="number" name="shipping_fee" value="{{ old('shipping_fee', 750) }}" class="h-11 w-full rounded-xl border-slate-300 pl-8 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-4">
                            <p class="text-xs font-black tracking-wider text-slate-500">利益計算式</p>
                            <p class="mt-2 text-sm font-black text-slate-700">売値 - 仕入れ値 - 手数料 - 送料</p>
                        </div>
                    </div>

                    @include('auction_items.partials.profit-tools')

                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-6 py-3 text-sm font-black text-white shadow transition hover:bg-blue-800">
                            登録する
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
            const imageInput = document.getElementById('images');
            const cameraImageInput = document.getElementById('camera-images');
            const imageDropZone = document.getElementById('image-drop-zone');
            const chooseImages = document.getElementById('choose-images');
            const takePhoto = document.getElementById('take-photo');
            const imageUploadError = document.getElementById('image-upload-error');
            const imagePreviewWrap = document.getElementById('image-preview-wrap');
            const imagePreviewList = document.getElementById('image-preview-list');
            const imageCount = document.getElementById('image-count');
            const maximumImageCount = 10;
            const maximumImageBytes = 2 * 1024 * 1024;
            const supportedImageTypes = ['image/jpeg', 'image/png', 'image/webp'];
            const selectedImages = [];

            platform?.addEventListener('change', function () {
                if (Object.prototype.hasOwnProperty.call(feeRates, platform.value)) {
                    salesFeeRate.value = feeRates[platform.value];
                }
            });

            function showImageUploadError(message) {
                imageUploadError.textContent = message;
                imageUploadError.classList.toggle('hidden', message === '');
            }

            function syncImageInput() {
                const transfer = new DataTransfer();

                selectedImages.forEach(function (entry) {
                    transfer.items.add(entry.file);
                });

                imageInput.files = transfer.files;
            }

            function renderImages() {
                imagePreviewList.replaceChildren();
                imageCount.textContent = `${selectedImages.length} / ${maximumImageCount} 枚`;
                imagePreviewWrap.classList.toggle('hidden', selectedImages.length === 0);

                selectedImages.forEach(function (entry, index) {
                    const card = document.createElement('div');
                    const preview = document.createElement('img');
                    const position = document.createElement('span');
                    const removeButton = document.createElement('button');

                    card.className = 'relative overflow-hidden rounded-lg border border-slate-200 bg-white';
                    card.draggable = true;
                    card.dataset.index = String(index);

                    preview.className = 'aspect-square w-full object-cover';
                    preview.src = entry.url;
                    preview.alt = `選択中の商品画像 ${index + 1}`;

                    position.className = 'absolute bottom-1 left-1 rounded bg-slate-900 px-1.5 py-0.5 text-xs font-black text-white';
                    position.textContent = String(index + 1);

                    removeButton.type = 'button';
                    removeButton.className = 'absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-base font-black text-slate-800 shadow hover:bg-slate-100';
                    removeButton.title = 'この画像を削除';
                    removeButton.setAttribute('aria-label', 'この画像を削除');
                    removeButton.textContent = '×';
                    removeButton.addEventListener('click', function () {
                        URL.revokeObjectURL(entry.url);
                        selectedImages.splice(index, 1);
                        syncImageInput();
                        renderImages();
                    });

                    card.addEventListener('dragstart', function (event) {
                        event.dataTransfer.setData('text/plain', String(index));
                        event.dataTransfer.effectAllowed = 'move';
                    });
                    card.addEventListener('dragover', function (event) {
                        event.preventDefault();
                    });
                    card.addEventListener('drop', function (event) {
                        event.preventDefault();
                        const sourceIndex = Number(event.dataTransfer.getData('text/plain'));

                        if (!Number.isInteger(sourceIndex) || sourceIndex === index) {
                            return;
                        }

                        const [movedImage] = selectedImages.splice(sourceIndex, 1);
                        selectedImages.splice(index, 0, movedImage);
                        syncImageInput();
                        renderImages();
                    });

                    card.append(preview, position, removeButton);
                    imagePreviewList.append(card);
                });
            }

            function addImages(files) {
                const acceptedImages = [];
                let errorMessage = '';

                Array.from(files).forEach(function (file) {
                    if (!supportedImageTypes.includes(file.type)) {
                        errorMessage = 'JPG、PNG、WEBP形式の画像を選択してください。';
                        return;
                    }
                    if (file.size > maximumImageBytes) {
                        errorMessage = '1枚あたり2MB以下の画像を選択してください。';
                        return;
                    }
                    if (selectedImages.length + acceptedImages.length >= maximumImageCount) {
                        errorMessage = `商品画像は最大${maximumImageCount}枚までです。`;
                        return;
                    }
                    acceptedImages.push({ file: file, url: URL.createObjectURL(file) });
                });

                selectedImages.push(...acceptedImages);
                showImageUploadError(errorMessage);
                syncImageInput();
                renderImages();
            }

            chooseImages?.addEventListener('click', function () {
                imageInput.click();
            });

            takePhoto?.addEventListener('click', function () {
                cameraImageInput.click();
            });

            imageInput?.addEventListener('change', function () {
                addImages(imageInput.files ?? []);
            });

            cameraImageInput?.addEventListener('change', function () {
                addImages(cameraImageInput.files ?? []);
                cameraImageInput.value = '';
            });

            ['dragenter', 'dragover'].forEach(function (eventName) {
                imageDropZone?.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    imageDropZone.classList.add('border-blue-600', 'bg-blue-50');
                });
            });

            ['dragleave', 'drop'].forEach(function (eventName) {
                imageDropZone?.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    imageDropZone.classList.remove('border-blue-600', 'bg-blue-50');
                });
            });

            imageDropZone?.addEventListener('drop', function (event) {
                addImages(event.dataTransfer.files);
            });

            window.addEventListener('beforeunload', function () {
                selectedImages.forEach(function (entry) {
                    URL.revokeObjectURL(entry.url);
                });
            });
        });
    </script>
</x-app-layout>
