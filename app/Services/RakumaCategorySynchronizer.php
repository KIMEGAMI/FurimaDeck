<?php

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RakumaCategorySynchronizer
{
    private const CATEGORY_URL = 'https://fril.jp/category';

    private const CATEGORY_LIST_MARKER = 'categoryList\\":';

    /**
     * Rakumaの公開カテゴリ一覧を取得して同期する。
     *
     * @return array{created: int, updated: int, deactivated: int, total: int}
     */
    public function synchronize(): array
    {
        $tree = $this->fetchTree();
        $result = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => 0];

        DB::transaction(function () use ($tree, &$result): void {
            foreach ($tree as $sortOrder => $node) {
                $this->synchronizeNode($node, null, $sortOrder, $result);
            }

            $result['deactivated'] = $this->deactivateUnusedLegacyCategories();
        });

        return $result;
    }

    /** @return list<array{name: string, source_id: int, source_path: string, children: list<array<string, mixed>>}> */
    private function fetchTree(): array
    {
        $html = Http::accept('text/html')
            ->withHeaders([
                'Accept-Language' => 'ja-JP,ja;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
            ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(2, 500)
            ->get(self::CATEGORY_URL)
            ->throw()
            ->body();

        return $this->treeFromHtml($html);
    }

    /**
     * Next.jsのページデータから、公開カテゴリの親子関係を復元する。
     *
     * @return list<array{name: string, source_id: int, source_path: string, children: list<array<string, mixed>>}>
     */
    private function treeFromHtml(string $html): array
    {
        $markerPosition = strpos($html, self::CATEGORY_LIST_MARKER);
        if ($markerPosition === false) {
            throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧を読み取れませんでした。');
        }

        $encodedArray = $this->extractEncodedJsonArray($html, $markerPosition + strlen(self::CATEGORY_LIST_MARKER));
        $json = json_decode('"'.$encodedArray.'"', true, 512, JSON_THROW_ON_ERROR);
        $categoryList = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($categoryList)) {
            throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧の形式が不正です。');
        }

        /** @var array<int, array{name: string, source_id: int, source_path: string, parent_source_id: int, children: list<array<string, mixed>>}> $nodes */
        $nodes = [];
        foreach ($categoryList as $category) {
            if (! is_array($category)
                || ! is_int($category['id'] ?? null)
                || ! is_int($category['parentId'] ?? null)
                || ! is_string($category['name'] ?? null)
                || trim($category['name']) === '') {
                throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧に不正な項目があります。');
            }

            $nodes[$category['id']] = [
                'name' => trim($category['name']),
                'source_id' => $category['id'],
                'source_path' => 'rakuma/'.$category['id'],
                'parent_source_id' => $category['parentId'],
                'children' => [],
            ];
        }

        if ($nodes === []) {
            throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧が空です。');
        }

        $rootIds = [];
        foreach ($nodes as $sourceId => $node) {
            if ($node['parent_source_id'] === 0) {
                $rootIds[] = $sourceId;

                continue;
            }

            if (! isset($nodes[$node['parent_source_id']])) {
                throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧の親子関係が不正です。');
            }

            $nodes[$node['parent_source_id']]['children'][] = $sourceId;
        }

        return array_map(fn (int $rootId): array => $this->buildNode($rootId, $nodes, []), $rootIds);
    }

    private function extractEncodedJsonArray(string $html, int $startPosition): string
    {
        if (($html[$startPosition] ?? null) !== '[') {
            throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧の開始位置が不正です。');
        }

        $depth = 0;
        for ($position = $startPosition; $position < strlen($html); $position++) {
            if ($html[$position] === '[') {
                $depth++;
            } elseif ($html[$position] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $startPosition, $position - $startPosition + 1);
                }
            }
        }

        throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧の終端が見つかりません。');
    }

    /**
     * @param  array<int, array{name: string, source_id: int, source_path: string, parent_source_id: int, children: list<int>}>  $nodes
     * @param  list<int>  $ancestorIds
     * @return array{name: string, source_id: int, source_path: string, children: list<array<string, mixed>>}
     */
    private function buildNode(int $sourceId, array $nodes, array $ancestorIds): array
    {
        if (in_array($sourceId, $ancestorIds, true)) {
            throw new \UnexpectedValueException('Rakumaの公開カテゴリ一覧に循環参照があります。');
        }

        $node = $nodes[$sourceId];
        $children = [];
        foreach ($node['children'] as $childId) {
            $children[] = $this->buildNode($childId, $nodes, [...$ancestorIds, $sourceId]);
        }

        return [
            'name' => $node['name'],
            'source_id' => $node['source_id'],
            'source_path' => $node['source_path'],
            'children' => $children,
        ];
    }

    /**
     * @param  array{name: string, source_id: int, source_path: string, children: list<array<string, mixed>>}  $node
     * @param  array{created: int, updated: int, deactivated: int, total: int}  $result
     */
    private function synchronizeNode(array $node, ?int $parentId, int $sortOrder, array &$result): void
    {
        $category = ProductCategory::query()->where('source_path', $node['source_path'])->first();

        if ($category === null) {
            $category = ProductCategory::query()
                ->whereNull('source_path')
                ->where('parent_id', $parentId)
                ->where('name', $node['name'])
                ->first();
        }

        $values = [
            'parent_id' => $parentId,
            'name' => $node['name'],
            'source_path' => $node['source_path'],
            'sort_order' => $sortOrder,
            'is_active' => true,
        ];

        if ($category === null) {
            $category = ProductCategory::query()->create([
                ...$values,
                'slug' => 'rakuma-'.$node['source_id'],
            ]);
            $result['created']++;
        } else {
            $category->fill($values);
            if ($category->isDirty()) {
                $category->save();
                $result['updated']++;
            }
        }

        $result['total']++;

        foreach ($node['children'] as $childSortOrder => $child) {
            /** @var array{name: string, source_id: int, source_path: string, children: list<array<string, mixed>>} $child */
            $this->synchronizeNode($child, $category->id, $childSortOrder, $result);
        }
    }

    private function deactivateUnusedLegacyCategories(): int
    {
        $deactivated = 0;

        do {
            $categoryIds = ProductCategory::query()
                ->select('id')
                ->whereNull('source_path')
                ->where('is_active', true)
                ->whereDoesntHave('products')
                ->whereDoesntHave('children', fn ($query) => $query->where('is_active', true))
                ->pluck('id');

            if ($categoryIds->isEmpty()) {
                break;
            }

            $deactivated += ProductCategory::query()
                ->whereIn('id', $categoryIds)
                ->update(['is_active' => false]);
        } while (true);

        return $deactivated;
    }
}
