<section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5" data-profit-tools>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-black tracking-widest text-emerald-700">PROFIT SUPPORT</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">値付け支援・利益シミュレーター</h3>
            <p class="mt-1 text-sm font-bold leading-6 text-emerald-900">仕入れ、手数料、送料から最低販売価格と値下げ限界を計算します。</p>
        </div>
        <div class="rounded-xl bg-white px-4 py-3 text-sm font-black text-slate-900 shadow-sm">
            目標利益率
            <input type="number" min="1" max="90" step="1" value="30" data-target-profit-rate class="ml-2 h-9 w-20 rounded-lg border-emerald-200 text-right text-sm font-black text-slate-950">
            %
        </div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-xs font-black text-slate-600">現在の見込み利益</p>
            <p class="mt-2 text-2xl font-black text-slate-950" data-current-profit>¥0</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-xs font-black text-slate-600">現在の利益率</p>
            <p class="mt-2 text-2xl font-black text-slate-950" data-current-margin>0.0%</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-xs font-black text-slate-600">最低販売価格</p>
            <p class="mt-2 text-2xl font-black text-slate-950" data-minimum-price>¥0</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-xs font-black text-slate-600">おすすめ価格</p>
            <p class="mt-2 text-2xl font-black text-emerald-700" data-recommended-price>¥0</p>
        </div>
    </div>

    <div class="mt-4">
        <button type="button" data-apply-recommended-price class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-black text-white shadow hover:bg-emerald-800">
            おすすめ価格を売値に入れる
        </button>
    </div>

    <p class="mt-3 text-sm font-bold leading-6 text-emerald-900" data-price-advice>
        売値、仕入れ値、送料を入力すると利益の目安を表示します。
    </p>
</section>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-profit-tools]').forEach(function (tool) {
                const form = tool.closest('form');
                if (!form) {
                    return;
                }

                const fields = {
                    purchasePrice: form.querySelector('[name="purchase_price"]'),
                    soldPrice: form.querySelector('[name="sold_price"]'),
                    salesFeeRate: form.querySelector('[name="sales_fee_rate"]'),
                    shippingFee: form.querySelector('[name="shipping_fee"]'),
                    targetProfitRate: tool.querySelector('[data-target-profit-rate]'),
                };
                const outputs = {
                    currentProfit: tool.querySelector('[data-current-profit]'),
                    currentMargin: tool.querySelector('[data-current-margin]'),
                    minimumPrice: tool.querySelector('[data-minimum-price]'),
                    recommendedPrice: tool.querySelector('[data-recommended-price]'),
                    advice: tool.querySelector('[data-price-advice]'),
                };
                const recommendedButton = tool.querySelector('[data-apply-recommended-price]');

                const yen = function (value) {
                    return '¥' + Math.max(0, Math.round(value)).toLocaleString();
                };
                const numberValue = function (input) {
                    const value = Number(input?.value ?? 0);
                    return Number.isFinite(value) ? Math.max(0, value) : 0;
                };
                const roundUpTo = function (value, unit) {
                    if (value <= 0) {
                        return 0;
                    }

                    return Math.ceil(value / unit) * unit;
                };
                const values = function () {
                    const purchasePrice = numberValue(fields.purchasePrice);
                    const soldPrice = numberValue(fields.soldPrice);
                    const salesFeeRate = Math.min(100, numberValue(fields.salesFeeRate));
                    const shippingFee = numberValue(fields.shippingFee);
                    const targetProfitRate = Math.min(90, Math.max(1, numberValue(fields.targetProfitRate) || 30));
                    const salesFee = Math.round(soldPrice * (salesFeeRate / 100));
                    const profit = soldPrice - purchasePrice - salesFee - shippingFee;
                    const margin = soldPrice > 0 ? (profit / soldPrice) * 100 : 0;
                    const minimumPrice = roundUpTo((purchasePrice + shippingFee) / Math.max(0.01, 1 - ((salesFeeRate + targetProfitRate) / 100)), 100);
                    const recommendedPrice = roundUpTo(minimumPrice * 1.1, 100);
                    const markdownLimit = roundUpTo((purchasePrice + shippingFee) / Math.max(0.01, 1 - (salesFeeRate / 100)), 100);

                    return { purchasePrice, soldPrice, salesFeeRate, shippingFee, targetProfitRate, salesFee, profit, margin, minimumPrice, recommendedPrice, markdownLimit };
                };
                const update = function () {
                    const current = values();
                    outputs.currentProfit.textContent = (current.profit < 0 ? '-' : '') + yen(Math.abs(current.profit));
                    outputs.currentProfit.className = 'mt-2 text-2xl font-black ' + (current.profit < 0 ? 'text-red-700' : 'text-slate-950');
                    outputs.currentMargin.textContent = current.margin.toFixed(1) + '%';
                    outputs.minimumPrice.textContent = yen(current.minimumPrice);
                    outputs.recommendedPrice.textContent = yen(current.recommendedPrice);

                    if (current.soldPrice <= 0) {
                        outputs.advice.textContent = '売値を入力すると、利益率と値下げ限界を確認できます。';
                    } else if (current.margin < current.targetProfitRate) {
                        outputs.advice.textContent = '目標利益率を下回っています。おすすめ価格以上での出品、または送料・仕入れ値の見直しがおすすめです。';
                    } else {
                        outputs.advice.textContent = '目標利益率を確保できています。値下げする場合の赤字回避ラインは ' + yen(current.markdownLimit) + ' です。';
                    }

                    recommendedButton.dataset.price = String(current.recommendedPrice);
                };

                ['input', 'change'].forEach(function (eventName) {
                    Object.values(fields).forEach(function (field) {
                        field?.addEventListener(eventName, update);
                    });
                });
                recommendedButton?.addEventListener('click', function () {
                    if (fields.soldPrice) {
                        fields.soldPrice.value = recommendedButton.dataset.price || '0';
                        fields.soldPrice.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
                update();
            });
        });
    </script>
@endonce
