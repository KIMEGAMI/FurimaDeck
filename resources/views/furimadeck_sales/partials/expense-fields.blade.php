<section class="space-y-4 border-t border-gray-200 pt-4">
    <h3 class="font-bold text-gray-900">販売時の別途費用</h3>
    <p class="text-sm leading-6 text-gray-800">販売時に別途支払った費用を入力します。ここで入力した金額は実利益の計算に使います。</p>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach($expenseLabels as $field => $label)
            <label class="block text-sm font-semibold text-gray-800">{{ $label }}
                <input required type="number" min="0" name="{{ $field }}" value="{{ old($field, $sale->{$field} ?? 0) }}" class="mt-1 block w-full rounded border-gray-300 text-gray-900">
            </label>
        @endforeach
    </div>
</section>
