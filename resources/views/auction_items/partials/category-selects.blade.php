@php
    $parentFieldName = $parentFieldName ?? 'parent_category_id';
    $categoryFieldName = $categoryFieldName ?? 'category_id';
    $parentSelectId = $parentSelectId ?? 'parent_category_id';
    $categorySelectId = $categorySelectId ?? 'category_id';
    $parentPlaceholder = $parentPlaceholder ?? '大ジャンルを選択';
    $categoryPlaceholder = $categoryPlaceholder ?? '小ジャンルを選択';
    $selectedParentId = (string) ($selectedParentId ?? old($parentFieldName, ''));
    $selectedCategoryId = (string) ($selectedCategoryId ?? old($categoryFieldName, ''));
    $categoryPayload = $parentCategories->map(fn ($parent) => [
        'id' => $parent->id,
        'name' => $parent->name,
        'children' => $parent->children->map(fn ($child) => [
            'id' => $child->id,
            'name' => $child->name,
        ])->values(),
    ])->values();
@endphp

<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    <div>
        <label for="{{ $parentSelectId }}" class="block text-xs font-black tracking-wider text-slate-600">
            大ジャンル
        </label>

        <select
            id="{{ $parentSelectId }}"
            name="{{ $parentFieldName }}"
            class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
        >
            <option value="">{{ $parentPlaceholder }}</option>
            @foreach ($parentCategories as $parentCategory)
                <option value="{{ $parentCategory->id }}" @selected($selectedParentId === (string) $parentCategory->id)>
                    {{ $parentCategory->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="{{ $categorySelectId }}" class="block text-xs font-black tracking-wider text-slate-600">
            小ジャンル
        </label>

        <select
            id="{{ $categorySelectId }}"
            name="{{ $categoryFieldName }}"
            class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
            data-selected-category="{{ $selectedCategoryId }}"
            data-placeholder="{{ $categoryPlaceholder }}"
        >
            <option value="">{{ $categoryPlaceholder }}</option>
        </select>
    </div>
</div>

@once
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.furugiCategoryGroups = @json($categoryPayload);

        window.initFurugiCategorySelects = function(parentSelectId, categorySelectId) {
            const parentSelect = document.getElementById(parentSelectId);
            const categorySelect = document.getElementById(categorySelectId);

            if (!parentSelect || !categorySelect) {
                return;
            }

            const groups = window.furugiCategoryGroups || [];

            const findParentIdByChild = function(childId) {
                if (!childId) {
                    return '';
                }

                for (const group of groups) {
                    if ((group.children || []).some((child) => String(child.id) === String(childId))) {
                        return String(group.id);
                    }
                }

                return '';
            };

            const renderChildren = function() {
                const selectedCategoryId = categorySelect.dataset.selectedCategory || '';

                if (!parentSelect.value && selectedCategoryId) {
                    parentSelect.value = findParentIdByChild(selectedCategoryId);
                }

                const selectedGroup = groups.find((group) => String(group.id) === String(parentSelect.value));
                categorySelect.replaceChildren(new Option(categorySelect.dataset.placeholder || '小ジャンルを選択', ''));

                for (const child of selectedGroup ? selectedGroup.children : []) {
                    const option = new Option(child.name, child.id);
                    option.selected = String(child.id) === String(selectedCategoryId);
                    categorySelect.append(option);
                }

                categorySelect.disabled = !selectedGroup;
            };

            parentSelect.addEventListener('change', function() {
                categorySelect.dataset.selectedCategory = '';
                renderChildren();
            });

            categorySelect.addEventListener('change', function() {
                categorySelect.dataset.selectedCategory = categorySelect.value;
            });

            renderChildren();
        };
    </script>
@endonce

<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.addEventListener('DOMContentLoaded', function() {
        window.initFurugiCategorySelects('{{ $parentSelectId }}', '{{ $categorySelectId }}');
    });
</script>
