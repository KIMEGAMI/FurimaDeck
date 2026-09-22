<?php

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class YahooFurimaCategorySynchronizer
{
    private const BASE_URL = 'https://paypayfleamarket.yahoo.co.jp';

    private const DEFAULT_REQUEST_INTERVAL_MILLISECONDS = 1000;

    /** @var array<string, list<string>> */
    private const LEGACY_NAME_ALIASES = [
        '13457/2494' => ['レディース'],
        '13457/2495' => ['メンズ'],
    ];

    /** @var array<string, bool> */
    private array $visitedPaths = [];

    private int $requestIntervalMilliseconds;

    public function __construct(?int $requestIntervalMilliseconds = null)
    {
        $this->requestIntervalMilliseconds = max(0, $requestIntervalMilliseconds ?? self::DEFAULT_REQUEST_INTERVAL_MILLISECONDS);
    }

    /**
     * @return array{created: int, updated: int, deactivated: int, total: int}
     *
     * @throws RequestException
     */
    public function synchronize(): array
    {
        $tree = $this->crawl('/category', []);
        $result = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => 0];

        DB::transaction(function () use ($tree, &$result): void {
            foreach ($tree as $sortOrder => $node) {
                $this->synchronizeNode($node, null, $sortOrder, $result);
            }

            $result['deactivated'] = $this->deactivateUnusedLegacyCategories();
        });

        return $result;
    }

    /**
     * @param  list<string>  $parentPath
     * @return list<array{name: string, source_path: string, children: list<array<string, mixed>>}>
     *
     * @throws RequestException
     */
    private function crawl(string $path, array $parentPath): array
    {
        $pathKey = implode('/', $parentPath);
        if (isset($this->visitedPaths[$pathKey])) {
            return [];
        }

        $this->visitedPaths[$pathKey] = true;
        $this->waitBeforeRequest();
        $html = Http::baseUrl(self::BASE_URL)
            ->accept('text/html')
            ->withHeaders([
                'Accept-Language' => 'ja-JP,ja;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
            ])
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(2, 500)
            ->get($path)
            ->throw()
            ->body();

        $children = $this->directChildrenFromHtml($html, $parentPath);

        foreach ($children as &$child) {
            $child['children'] = $this->crawl('/category/'.$child['source_path'], explode('/', $child['source_path']));
        }
        unset($child);

        return $children;
    }

    private function waitBeforeRequest(): void
    {
        if ($this->requestIntervalMilliseconds === 0 || $this->visitedPaths === ['' => true]) {
            return;
        }

        usleep($this->requestIntervalMilliseconds * 1000);
    }

    /**
     * @param  list<string>  $parentPath
     * @return list<array{name: string, source_path: string}>
     */
    private function directChildrenFromHtml(string $html, array $parentPath): array
    {
        $document = new \DOMDocument;
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $xpath = new \DOMXPath($document);
        $children = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            $segments = $this->categoryPathSegments($anchor->getAttribute('href'));
            if ($segments === null || count($segments) !== count($parentPath) + 1) {
                continue;
            }

            if (array_slice($segments, 0, count($parentPath)) !== $parentPath) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));
            if ($name === '') {
                continue;
            }

            $sourcePath = implode('/', $segments);
            $children[$sourcePath] ??= [
                'name' => $name,
                'source_path' => $sourcePath,
            ];
        }

        return array_values($children);
    }

    /** @return list<string>|null */
    private function categoryPathSegments(string $href): ?array
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (array_shift($segments) !== 'category' || $segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            if (! ctype_digit($segment)) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * @param  array{name: string, source_path: string, children: list<array<string, mixed>>}  $node
     * @param  array{created: int, updated: int, deactivated: int, total: int}  $result
     */
    private function synchronizeNode(array $node, ?int $parentId, int $sortOrder, array &$result): void
    {
        $category = ProductCategory::query()->where('source_path', $node['source_path'])->first();

        if ($category === null) {
            $category = $this->findCompatibleCategory($parentId, $node['name'], $node['source_path']);
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
                'slug' => 'yahoo-furima-'.str_replace('/', '-', $node['source_path']),
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
            /** @var array{name: string, source_path: string, children: list<array<string, mixed>>} $child */
            $this->synchronizeNode($child, $category->id, $childSortOrder, $result);
        }
    }

    private function findCompatibleCategory(?int $parentId, string $name, string $sourcePath): ?ProductCategory
    {
        $names = array_merge([$name], self::LEGACY_NAME_ALIASES[$sourcePath] ?? []);

        return ProductCategory::query()
            ->whereNull('source_path')
            ->where('parent_id', $parentId)
            ->whereIn('name', $names)
            ->orderByRaw('case when name = ? then 0 else 1 end', [$name])
            ->first();
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
