@php
    $parentFieldName = $parentFieldName ?? 'parent_category_id';
    $categoryFieldName = $categoryFieldName ?? 'category_id';
    $parentSelectId = $parentSelectId ?? 'parent_category_id';
    $middleSelectId = $middleSelectId ?? str_replace('parent_', 'middle_', $parentSelectId);
    $categorySelectId = $categorySelectId ?? 'category_id';
    $parentPlaceholder = $parentPlaceholder ?? '大ジャンルを選択';
    $middlePlaceholder = $middlePlaceholder ?? '中ジャンルを選択';
    $categoryPlaceholder = $categoryPlaceholder ?? '小ジャンルを選択';
    $selectedParentId = (string) ($selectedParentId ?? old($parentFieldName, ''));
    $selectedCategoryId = (string) ($selectedCategoryId ?? old($categoryFieldName, ''));
    $categoryPayload = $parentCategories->map(fn ($parent) => [
        'id' => $parent->id,
        'name' => $parent->name,
        'children' => $parent->children->map(fn ($middle) => [
            'id' => $middle->id,
            'name' => $middle->name,
            'children' => $middle->children->map(fn ($leaf) => [
                'id' => $leaf->id,
                'name' => $leaf->name,
            ])->values(),
        ])->values(),
    ])->values();
@endphp

<div class="grid grid-cols-1 gap-5 md:grid-cols-3">
    <div>
        <label for="{{ $parentSelectId }}" class="block text-xs font-black tracking-wider text-slate-600">大ジャンル</label>
        <select id="{{ $parentSelectId }}" name="{{ $parentFieldName }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="">{{ $parentPlaceholder }}</option>
            @foreach ($parentCategories as $parentCategory)
                <option value="{{ $parentCategory->id }}" @selected($selectedParentId === (string) $parentCategory->id)>{{ $parentCategory->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="{{ $middleSelectId }}" class="block text-xs font-black tracking-wider text-slate-600">中ジャンル</label>
        <select id="{{ $middleSelectId }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" data-placeholder="{{ $middlePlaceholder }}">
            <option value="">{{ $middlePlaceholder }}</option>
        </select>
    </div>

    <div>
        <label for="{{ $categorySelectId }}" class="block text-xs font-black tracking-wider text-slate-600">小ジャンル</label>
        <select id="{{ $categorySelectId }}" name="{{ $categoryFieldName }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" data-placeholder="{{ $categoryPlaceholder }}" data-selected-category="{{ $selectedCategoryId }}">
            <option value="">{{ $categoryPlaceholder }}</option>
        </select>
    </div>
</div>

@once
    <script>
        window.furugiCategoryGroupsV2 = @json($categoryPayload);

        window.initFurugiCategorySelectsV2 = function (parentSelectId, middleSelectId, categorySelectId) {
            const parentSelect = document.getElementById(parentSelectId);
            const middleSelect = document.getElementById(middleSelectId);
            const categorySelect = document.getElementById(categorySelectId);

            if (!parentSelect || !middleSelect || !categorySelect) {
                return;
            }

            const groups = window.furugiCategoryGroupsV2 || [];
            let selectedCategoryId = categorySelect.dataset.selectedCategory || '';
            let selectedMiddleId = '';

            const findPath = function (categoryId) {
                for (const parent of groups) {
                    for (const middle of parent.children || []) {
                        if (String(middle.id) === String(categoryId)) {
                            return { parentId: String(parent.id), middleId: String(middle.id), categoryId: String(middle.id) };
                        }

                        for (const leaf of middle.children || []) {
                            if (String(leaf.id) === String(categoryId)) {
                                return { parentId: String(parent.id), middleId: String(middle.id), categoryId: String(leaf.id) };
                            }
                        }
                    }
                }

                return null;
            };

            const selectedParent = function () {
                return groups.find((parent) => String(parent.id) === String(parentSelect.value));
            };

            const selectedMiddle = function () {
                return (selectedParent()?.children || []).find((middle) => String(middle.id) === String(middleSelect.value));
            };

            const renderMiddle = function () {
                const parent = selectedParent();
                middleSelect.replaceChildren(new Option(middleSelect.dataset.placeholder || '中ジャンルを選択', ''));

                for (const middle of parent?.children || []) {
                    middleSelect.append(new Option(middle.name, middle.id));
                }

                middleSelect.disabled = !parent;
                middleSelect.value = selectedMiddleId;
            };

            const renderLeaf = function () {
                const middle = selectedMiddle();
                categorySelect.replaceChildren(new Option(categorySelect.dataset.placeholder || '小ジャンルを選択', ''));

                if (!middle) {
                    categorySelect.disabled = true;
                    return;
                }

                const leaves = middle.children || [];

                if (leaves.length === 0) {
                    categorySelect.append(new Option(`${middle.name}（中ジャンルを最終ジャンルとして使用）`, middle.id));
                    categorySelect.value = String(middle.id);
                    categorySelect.disabled = false;
                    return;
                }

                for (const leaf of leaves) {
                    categorySelect.append(new Option(leaf.name, leaf.id));
                }

                categorySelect.value = selectedCategoryId;
                categorySelect.disabled = false;
            };

            const path = findPath(selectedCategoryId);
            if (path) {
                parentSelect.value = path.parentId;
                selectedMiddleId = path.middleId;
            }

            renderMiddle();
            renderLeaf();

            parentSelect.addEventListener('change', function () {
                selectedMiddleId = '';
                selectedCategoryId = '';
                renderMiddle();
                renderLeaf();
            });

            middleSelect.addEventListener('change', function () {
                selectedMiddleId = middleSelect.value;
                selectedCategoryId = '';
                renderLeaf();
            });

            categorySelect.addEventListener('change', function () {
                selectedCategoryId = categorySelect.value;
            });
        };
    </script>
@endonce

<script>
    document.addEventListener('DOMContentLoaded', function () {
        window.initFurugiCategorySelectsV2('{{ $parentSelectId }}', '{{ $middleSelectId }}', '{{ $categorySelectId }}');
    });
</script>
