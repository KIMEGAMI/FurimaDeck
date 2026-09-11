<?php

namespace App\Http\Controllers;

use App\Models\AuctionItem;
use App\Models\AuctionItemImage;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuctionItemController extends Controller
{
    private const CSV_MAX_ROWS = 5000;

    private const CSV_MAX_CELL_LENGTH = 1000;

    private const IMAGE_MAX_KILOBYTES = 2048;

    private const IMAGE_MAX_COUNT = 10;

    private const UNSOLD_DEFAULT_DAYS = 10;

    private const YAHOO_AUCTION_REQUIRED_HEADERS = [
        '取扱内容',
        '商品ID',
        '取扱日',
        '状態',
        '決済金額',
        '送料',
    ];

    private const YAHOO_AUCTION_SALE_STATUS = '売上金';

    private const MERCARI_SHOPS_REQUIRED_HEADERS = [
        '注文番号',
        '明細番号',
        '購入日',
        '商品名',
        '売上（税込）',
        'メルカリ便送料（税込）',
        '送料（税込）',
        '販売手数料（税込）',
        '販売手数料率（%）',
        'ショップ名',
    ];

    public function index(Request $request)
    {
        $status = $request->get('status');
        $platform = $request->get('platform');
        $keyword = $request->get('keyword');
        $unsoldBeforeDate = $this->parseUnsoldBeforeDate($request->query('unsold_before'));
        $unsoldOnly = $request->boolean('unsold') || $unsoldBeforeDate !== null;
        $parentCategoryId = $request->integer('parent_category_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;

        $query = AuctionItem::with(['category.parent.parent'])
            ->where('user_id', Auth::id());

        if ($unsoldOnly) {
            $query->where('status', AuctionItem::STATUS_SELLING);

            if ($unsoldBeforeDate) {
                $query->where('created_at', '<=', $unsoldBeforeDate->copy()->endOfDay());
            } else {
                $query->where('created_at', '<=', now()->subDays(self::UNSOLD_DEFAULT_DAYS)->endOfDay());
            }
        } elseif (in_array($status, AuctionItem::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (in_array($platform, AuctionItem::PLATFORMS, true)) {
            $query->whereIn('platform', AuctionItem::platformFilterValues($platform));
        }

        if (is_string($keyword) && trim($keyword) !== '') {
            $keyword = trim($keyword);
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('management_id', 'like', '%'.$keyword.'%')
                    ->orWhere('title', 'like', '%'.$keyword.'%')
                    ->orWhere('comment', 'like', '%'.$keyword.'%');
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        } elseif ($parentCategoryId) {
            $query->whereIn('category_id', $this->categoryAndDescendantIds($parentCategoryId));
        }

        $sellingAdviceItems = $this->sellingAdviceItems((int) Auth::id());

        return view('auction_items.index', [
            'auctionItems' => ($unsoldOnly
                ? $query->orderBy('created_at')->orderBy('id')
                : $query->latest()
            )->paginate(12)->withQueryString(),
            'status' => $status,
            'platform' => $platform,
            'keyword' => $keyword,
            'unsoldOnly' => $unsoldOnly,
            'unsoldBeforeInput' => $unsoldBeforeDate?->format('Ymd') ?? '',
            'unsoldBeforeDate' => $unsoldBeforeDate?->format('Y-m-d') ?? '',
            'unsoldFilterLabel' => $unsoldBeforeDate
                ? $unsoldBeforeDate->format('Y/m/d').'以前の未売却'
                : self::UNSOLD_DEFAULT_DAYS.'日以上未売却',
            'parentCategoryId' => $parentCategoryId,
            'categoryId' => $categoryId,
            'parentCategories' => $this->parentCategories(),
            'platforms' => AuctionItem::PLATFORMS,
            'inventoryAlerts' => $this->inventoryAlerts($sellingAdviceItems),
        ]);
    }

    public function unsoldAlerts(Request $request)
    {
        $threshold = $request->integer('threshold') === 30 ? 30 : 14;
        $thresholdDate = now()->subDays($threshold)->endOfDay();

        $query = AuctionItem::query()
            ->with(['category.parent'])
            ->where('user_id', Auth::id())
            ->where('status', AuctionItem::STATUS_SELLING)
            ->where('created_at', '<=', $thresholdDate)
            ->orderBy('created_at')
            ->orderBy('id');

        $alertItems = $query
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuctionItem $item): array => [
                'item' => $item,
                'days_listed' => $this->daysListed($item),
                'category_label' => $this->categoryLabel($item),
                'platform_label' => AuctionItem::normalizePlatformName($item->platform) ?: '未設定',
            ]);

        $sellingAdviceItems = $this->sellingAdviceItems((int) Auth::id());

        return view('auction_items.unsold-alerts', [
            'alertItems' => $alertItems,
            'threshold' => $threshold,
            'inventoryAlerts' => $this->inventoryAlerts($sellingAdviceItems),
        ]);
    }

    private function sellingAdviceItems(int $userId): Collection
    {
        return AuctionItem::query()
            ->with(['category.parent'])
            ->where('user_id', $userId)
            ->where('status', AuctionItem::STATUS_SELLING)
            ->orderBy('created_at')
            ->limit(500)
            ->get();
    }

    private function inventoryAlerts(Collection $items): array
    {
        $now = now();
        $olderThan30 = $items->filter(fn (AuctionItem $item) => $item->created_at && $item->created_at->diffInDays($now) >= 30);
        $olderThan14 = $items->filter(fn (AuctionItem $item) => $item->created_at && $item->created_at->diffInDays($now) >= 14);
        $totalCost = $items->sum(fn (AuctionItem $item) => (int) ($item->purchase_price ?? 0));
        $staleCost = $olderThan30->sum(fn (AuctionItem $item) => (int) ($item->purchase_price ?? 0));

        return [
            'selling_count' => $items->count(),
            'older_than_14_count' => $olderThan14->count(),
            'older_than_30_count' => $olderThan30->count(),
            'total_cost' => $totalCost,
            'stale_cost' => $staleCost,
            'stale_cost_rate' => $totalCost > 0 ? round(($staleCost / $totalCost) * 100, 1) : 0.0,
            'message' => $olderThan30->count() > 0
                ? '30日以上動いていない在庫があります。値下げ、写真差し替え、説明文の見直し候補を確認してください。'
                : '30日以上の滞留在庫はありません。現在の出品ペースを維持できます。',
        ];
    }

    private function daysListed(AuctionItem $item): int
    {
        return $item->created_at ? max(0, (int) $item->created_at->diffInDays(now())) : 0;
    }

    private function categoryLabel(AuctionItem $item): string
    {
        if (! $item->category) {
            return '未設定';
        }

        return ($item->category->parent?->name ? $item->category->parent->name.' / ' : '').$item->category->name;
    }

    private function parseUnsoldBeforeDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = mb_convert_kana($value, 'n');
        $normalized = preg_replace('/[^\d-]/', '', $normalized) ?? '';

        if (preg_match('/^\d{8}$/', $normalized) === 1) {
            try {
                return Carbon::createFromFormat('Ymd', $normalized)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $normalized)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function create()
    {
        return view('auction_items.create', [
            'parentCategories' => $this->parentCategories(),
            'platforms' => AuctionItem::PLATFORMS,
            'salesFeeRates' => AuctionItem::SALES_FEE_RATES,
        ]);
    }

    public function csvImport()
    {
        return view('auction_items.csv-import');
    }

    public function duplicates()
    {
        return view('auction_items.duplicates', [
            'duplicateGroups' => $this->duplicateAuctionItemGroups(),
        ]);
    }

    public function store(Request $request)
    {
        $limitResponse = $this->ensureFreeAuctionItemLimit($request);

        if ($limitResponse) {
            return $limitResponse;
        }

        $validated = $this->validateAuctionItem($request);

        $categoryLimitResponse = $this->ensureFreeCategoryLimit($request, $validated['category_id'] ?? null);

        if ($categoryLimitResponse) {
            return $categoryLimitResponse;
        }

        $platform = $this->normalizePlatform($validated['platform']);
        $soldPrice = (int) ($validated['sold_price'] ?? 0);
        $purchasePrice = (int) ($validated['purchase_price'] ?? 0);
        $shippingFee = (int) ($validated['shipping_fee'] ?? 0);
        $salesFeeRate = (float) ($validated['sales_fee_rate'] ?? $this->defaultSalesFeeRate($platform));
        $salesFee = $this->calculateSalesFee($soldPrice, $salesFeeRate);

        $imageFiles = $this->auctionItemImageFiles($request);
        $this->ensureAuctionItemImageLimit($imageFiles);

        $auctionItem = AuctionItem::create([
            'user_id' => Auth::id(),
            'management_id' => $validated['management_id'],
            'title' => $validated['title'],
            'comment' => $validated['comment'] ?? null,
            'platform' => $platform,
            'category_id' => $validated['category_id'] ?? null,
            'image_path' => null,
            'sold_image_path' => null,
            'purchase_price' => $purchasePrice,
            'sold_price' => $soldPrice,
            'sales_fee_rate' => $salesFeeRate,
            'sales_fee' => $salesFee,
            'shipping_fee' => $shippingFee,
            'profit' => 0,
            'sold_at' => null,
            'status' => AuctionItem::STATUS_SELLING,
        ]);

        $this->storeAuctionItemImages($auctionItem, $imageFiles);

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品を登録しました。');
    }

    public function importCsv(Request $request)
    {

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'csv_file.required' => 'CSVファイルを選択してください。',
            'csv_file.mimes' => 'CSVファイルを選択してください。',
        ]);

        $filePath = $request->file('csv_file')->getRealPath();

        if (! $filePath || ! file_exists($filePath)) {
            return $this->csvImportError('CSVファイルを読み込めませんでした。');
        }

        $csvText = file_get_contents($filePath);

        if ($csvText === false || trim($csvText) === '') {
            return $this->csvImportError('CSVファイルが空です。');
        }

        $encoding = mb_detect_encoding($csvText, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932', 'EUC-JP'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $csvText = mb_convert_encoding($csvText, 'UTF-8', $encoding);
        }

        $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);
        $handle = fopen('php://temp', 'r+');

        if (! $handle) {
            return $this->csvImportError('CSVファイルを処理できませんでした。');
        }

        fwrite($handle, $csvText);
        rewind($handle);

        $headers = fgetcsv($handle);

        if (! $headers) {
            fclose($handle);

            return $this->csvImportError('CSVのヘッダー行を読み込めませんでした。');
        }

        $headers = $this->normalizeFurugiImportHeaders($headers);
        $missingHeaders = array_diff(['management_id', 'title'], $headers);

        if ($missingHeaders !== []) {
            fclose($handle);

            return $this->csvImportError('CSVには management_id と title のヘッダーが必要です。');
        }

        $importedCount = 0;
        $skippedCount = 0;
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber > self::CSV_MAX_ROWS + 1) {
                fclose($handle);

                return $this->csvImportError('CSVは最大'.number_format(self::CSV_MAX_ROWS).'行まで取り込めます。行数を分けて再実行してください。');
            }

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, array_map(
                fn ($value) => $this->sanitizeCsvCell($value),
                array_slice($row, 0, count($headers))
            ));

            if (! $data) {
                $skippedCount++;

                continue;
            }

            $managementId = trim((string) ($data['management_id'] ?? ''));
            $title = trim((string) ($data['title'] ?? ''));

            if ($managementId === '' || $title === '') {
                $skippedCount++;

                continue;
            }

            if (AuctionItem::where('user_id', Auth::id())->where('management_id', $managementId)->exists()) {
                $skippedCount++;

                continue;
            }

            $platform = $this->normalizePlatform($data['platform'] ?? AuctionItem::PLATFORM_OTHER);
            $purchasePrice = $this->parseCsvMoney($data['purchase_price'] ?? 0);
            $soldPrice = $this->parseCsvMoney($data['sold_price'] ?? 0);
            $shippingFee = $this->parseCsvMoney($data['shipping_fee'] ?? 0);
            $salesFeeRate = ($data['sales_fee_rate'] ?? '') !== ''
                ? ($this->parseCsvRate($data['sales_fee_rate']) ?? $this->defaultSalesFeeRate($platform))
                : $this->defaultSalesFeeRate($platform);
            $salesFee = ($data['sales_fee'] ?? '') !== ''
                ? $this->parseCsvMoney($data['sales_fee'])
                : $this->calculateSalesFee($soldPrice, $salesFeeRate);
            $status = $this->normalizeStatus($data['status'] ?? AuctionItem::STATUS_SELLING);
            $profit = $status === AuctionItem::STATUS_SOLD
                ? $this->calculateProfit($soldPrice, $purchasePrice, $salesFee, $shippingFee)
                : 0;

            AuctionItem::create([
                'user_id' => Auth::id(),
                'management_id' => $managementId,
                'title' => $title,
                'comment' => trim((string) ($data['comment'] ?? '')) ?: null,
                'platform' => $platform,
                'category_id' => $this->resolveCsvCategoryId(
                    $data['大ジャンル'] ?? $data['parent_category'] ?? null,
                    $data['小ジャンル'] ?? $data['category'] ?? null
                ),
                'image_path' => null,
                'sold_image_path' => null,
                'purchase_price' => $purchasePrice,
                'sold_price' => $soldPrice,
                'sales_fee_rate' => $salesFeeRate,
                'sales_fee' => $salesFee,
                'shipping_fee' => $shippingFee,
                'profit' => $profit,
                'sold_at' => $status === AuctionItem::STATUS_SOLD
                    ? ($this->parseCsvSoldAt($data['sold_at'] ?? null) ?? now())
                    : null,
                'status' => $status,
            ]);

            $importedCount++;
        }

        fclose($handle);

        return redirect()
            ->route('auction-items.index')
            ->with('success', "CSVインポートが完了しました。登録 {$importedCount} 件 / スキップ {$skippedCount} 件");
    }

    public function importYahooAuctionCsv(Request $request)
    {

        $request->validate([
            'yahoo_csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'yahoo_csv_file.required' => 'ヤフオク売上CSVファイルを選択してください。',
            'yahoo_csv_file.mimes' => 'ヤフオク売上CSVファイルを選択してください。',
        ]);

        $handle = $this->openUploadedCsv($request, 'yahoo_csv_file');

        if (! is_resource($handle)) {
            return $handle;
        }

        $headers = fgetcsv($handle);

        if (! $headers) {
            fclose($handle);

            return $this->csvImportError('ヤフオクCSVのヘッダー行を読み込めませんでした。');
        }

        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        $missingHeaders = array_diff(self::YAHOO_AUCTION_REQUIRED_HEADERS, $headers);

        if ($missingHeaders !== []) {
            fclose($handle);

            return $this->csvImportError('ヤフオクCSVに必要な列がありません。不足: '.implode(', ', $missingHeaders));
        }

        $importedCount = 0;
        $skippedCount = 0;
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber > self::CSV_MAX_ROWS + 1) {
                fclose($handle);

                return $this->csvImportError('CSVは最大'.number_format(self::CSV_MAX_ROWS).'行まで取り込めます。行数を分けて再実行してください。');
            }

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, array_map(
                fn ($value) => $this->sanitizeCsvCell($value),
                array_slice($row, 0, count($headers))
            ));

            if (! $data || ! $this->isImportableYahooAuctionSale($data)) {
                $skippedCount++;

                continue;
            }

            $managementId = trim((string) $data['商品ID']);
            $title = trim((string) $data['取扱内容']);

            if (AuctionItem::where('user_id', Auth::id())->where('management_id', $managementId)->exists()) {
                $skippedCount++;

                continue;
            }

            $soldPrice = $this->parseCsvMoney($data['決済金額'] ?? null);
            $salesFee = $this->parseYahooAuctionSalesFee($data);
            $shippingFee = $this->parseCsvMoney($data['送料'] ?? null);
            $salesFeeRate = $soldPrice > 0
                ? round(($salesFee / $soldPrice) * 100, 2)
                : $this->defaultSalesFeeRate(AuctionItem::PLATFORM_YAHOO);

            AuctionItem::create([
                'user_id' => Auth::id(),
                'management_id' => $managementId,
                'title' => $title,
                'comment' => $this->buildYahooAuctionComment($data),
                'platform' => AuctionItem::PLATFORM_YAHOO,
                'category_id' => $this->resolveExistingAuctionItemCategoryId($title, AuctionItem::PLATFORM_YAHOO, $managementId),
                'image_path' => null,
                'sold_image_path' => null,
                'purchase_price' => 0,
                'sold_price' => $soldPrice,
                'sales_fee_rate' => $salesFeeRate,
                'sales_fee' => $salesFee,
                'shipping_fee' => $shippingFee,
                'profit' => $this->calculateProfit($soldPrice, 0, $salesFee, $shippingFee),
                'sold_at' => $this->parseCsvSoldAt($data['取扱日'] ?? null) ?? now(),
                'status' => AuctionItem::STATUS_SOLD,
            ]);

            $importedCount++;
        }

        fclose($handle);

        return redirect()
            ->route('auction-items.index', ['status' => AuctionItem::STATUS_SOLD])
            ->with('success', "ヤフオクCSVを変換して一括登録しました。登録 {$importedCount} 件 / スキップ {$skippedCount} 件");
    }

    public function importMercariShopsCsv(Request $request)
    {
        $request->validate([
            'mercari_shops_csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ], [
            'mercari_shops_csv_file.required' => 'メルカリShops CSVファイルを選択してください。',
            'mercari_shops_csv_file.mimes' => 'メルカリShops CSVファイルを選択してください。',
        ]);

        $handle = $this->openUploadedCsv($request, 'mercari_shops_csv_file');

        if (! is_resource($handle)) {
            return $handle;
        }

        $headers = fgetcsv($handle);

        if (! $headers) {
            fclose($handle);

            return $this->csvImportError('メルカリShops CSVのヘッダー行を読み込めませんでした。');
        }

        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        $missingHeaders = array_diff(self::MERCARI_SHOPS_REQUIRED_HEADERS, $headers);

        if ($missingHeaders !== []) {
            fclose($handle);

            return $this->csvImportError('メルカリShops CSVに必要な列がありません。不足: '.implode(', ', $missingHeaders));
        }

        $importedCount = 0;
        $skippedCount = 0;
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber > self::CSV_MAX_ROWS + 1) {
                fclose($handle);

                return $this->csvImportError('CSVは最大'.number_format(self::CSV_MAX_ROWS).'行まで取り込めます。行数を分けて再実行してください。');
            }

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, array_map(
                fn ($value) => $this->sanitizeCsvCell($value),
                array_slice($row, 0, count($headers))
            ));

            if (! $data || ! $this->isImportableMercariShopsSale($data)) {
                $skippedCount++;

                continue;
            }

            $managementId = $this->mercariShopsManagementId($data);

            if (AuctionItem::where('user_id', Auth::id())->where('management_id', $managementId)->exists()) {
                $skippedCount++;

                continue;
            }

            $soldPrice = $this->parseCsvMoney($data['売上（税込）'] ?? null);
            $salesFee = $this->parseCsvMoney($data['販売手数料（税込）'] ?? null);
            $shippingFee = $this->parseMercariShopsShippingFee($data);
            $salesFeeRate = $this->parseCsvRate($data['販売手数料率（%）'] ?? null);

            AuctionItem::create([
                'user_id' => Auth::id(),
                'management_id' => $managementId,
                'title' => trim((string) $data['商品名']),
                'comment' => $this->buildMercariShopsComment($data),
                'platform' => AuctionItem::PLATFORM_MERCARI,
                'category_id' => $this->resolveExistingAuctionItemCategoryId(trim((string) $data['商品名']), AuctionItem::PLATFORM_MERCARI, $managementId),
                'image_path' => null,
                'sold_image_path' => null,
                'purchase_price' => 0,
                'sold_price' => $soldPrice,
                'sales_fee_rate' => $salesFeeRate ?? $this->defaultSalesFeeRate(AuctionItem::PLATFORM_MERCARI),
                'sales_fee' => $salesFee,
                'shipping_fee' => $shippingFee,
                'profit' => $this->calculateProfit($soldPrice, 0, $salesFee, $shippingFee),
                'sold_at' => $this->parseCsvSoldAt($data['購入日'] ?? null) ?? now(),
                'status' => AuctionItem::STATUS_SOLD,
            ]);

            $importedCount++;
        }

        fclose($handle);

        return redirect()
            ->route('auction-items.index', ['status' => AuctionItem::STATUS_SOLD])
            ->with('success', "メルカリShops CSVを変換して一括登録しました。登録 {$importedCount} 件 / スキップ {$skippedCount} 件");
    }

    public function show(AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);
        $auctionItem->load('category.parent');

        return view('auction_items.show', [
            'auctionItem' => $auctionItem,
        ]);
    }

    public function edit(AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);
        $auctionItem->load('category.parent');

        return view('auction_items.edit', [
            'auctionItem' => $auctionItem,
            'parentCategories' => $this->parentCategories(),
            'platforms' => AuctionItem::PLATFORMS,
            'salesFeeRates' => AuctionItem::SALES_FEE_RATES,
        ]);
    }

    public function update(Request $request, AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);
        $validated = $this->validateAuctionItem($request, $auctionItem);
        $categoryLimitResponse = $this->ensureFreeCategoryLimit($request, $validated['category_id'] ?? null, $auctionItem);

        if ($categoryLimitResponse) {
            return $categoryLimitResponse;
        }

        $imageFile = $this->auctionItemImageFile($request);

        if ($imageFile) {
            $this->deleteAuctionItemImages($auctionItem);
            $this->deleteAuctionItemImage($auctionItem->image_path, $auctionItem->id);
            $this->deleteAuctionItemImage($auctionItem->sold_image_path, $auctionItem->id);
            $auctionItem->image_path = $imageFile->store('auction-items', 'public');
            $auctionItem->sold_image_path = null;
        }

        $auctionItem->management_id = $validated['management_id'];
        $auctionItem->title = $validated['title'];
        $auctionItem->comment = $validated['comment'] ?? null;
        $auctionItem->platform = $this->normalizePlatform($validated['platform']);
        $auctionItem->category_id = $validated['category_id'] ?? null;
        $auctionItem->purchase_price = (int) ($validated['purchase_price'] ?? 0);
        $auctionItem->sold_price = (int) ($validated['sold_price'] ?? 0);
        $auctionItem->shipping_fee = (int) ($validated['shipping_fee'] ?? 0);
        $auctionItem->sales_fee_rate = (float) ($validated['sales_fee_rate'] ?? $this->defaultSalesFeeRate($auctionItem->platform));
        $auctionItem->sales_fee = $this->calculateSalesFee($auctionItem->sold_price, $auctionItem->sales_fee_rate);

        if ($auctionItem->status === AuctionItem::STATUS_SOLD) {
            $auctionItem->profit = $this->calculateProfit(
                $auctionItem->sold_price,
                $auctionItem->purchase_price,
                $auctionItem->sales_fee,
                $auctionItem->shipping_fee
            );
            $auctionItem->sold_image_path = $auctionItem->image_path
                ? $this->createSoldImage($auctionItem->image_path, $auctionItem->sold_image_path)
                : null;
        }

        $auctionItem->save();

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品を更新しました。');
    }

    public function markAsSold(AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);

        $auctionItem->status = AuctionItem::STATUS_SOLD;
        $auctionItem->sales_fee_rate = (float) ($auctionItem->sales_fee_rate ?: $this->defaultSalesFeeRate($auctionItem->platform));
        $auctionItem->sales_fee = $this->calculateSalesFee((int) $auctionItem->sold_price, (float) $auctionItem->sales_fee_rate);
        $auctionItem->profit = $this->calculateProfit(
            (int) $auctionItem->sold_price,
            (int) $auctionItem->purchase_price,
            (int) $auctionItem->sales_fee,
            (int) $auctionItem->shipping_fee
        );
        $auctionItem->sold_at = now();
        $auctionItem->sold_image_path = $auctionItem->image_path
            ? $this->createSoldImage($auctionItem->image_path, $auctionItem->sold_image_path)
            : null;
        $auctionItem->save();

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品をSOLDにしました。');
    }

    public function markAsSelling(AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);
        $this->deleteAuctionItemImage($auctionItem->sold_image_path, $auctionItem->id);

        $auctionItem->status = AuctionItem::STATUS_SELLING;
        $auctionItem->sold_at = null;
        $auctionItem->profit = 0;
        $auctionItem->sold_image_path = null;
        $auctionItem->save();

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品を出品中に戻しました。');
    }

    public function confirmBulkDestroy(Request $request)
    {
        $itemCount = AuctionItem::query()
            ->where('user_id', Auth::id())
            ->count();

        return view('auction_items.bulk-destroy-confirm', [
            'itemCount' => $itemCount,
            'hasActiveSubscription' => (bool) $request->user()?->hasActiveSubscription(),
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'confirm_delete_all_items' => ['accepted'],
        ], [
            'confirm_delete_all_items.accepted' => '確認チェックを入れてから削除してください。',
        ]);

        $deletedCount = 0;

        AuctionItem::query()
            ->where('user_id', Auth::id())
            ->select(['id', 'image_path', 'sold_image_path'])
            ->orderBy('id')
            ->chunkById(100, function ($items) use (&$deletedCount) {
                foreach ($items as $item) {
                    $this->deleteAuctionItemImages($item);
                    $this->deleteAuctionItemImage($item->image_path, $item->id);
                    $this->deleteAuctionItemImage($item->sold_image_path, $item->id);
                    $item->delete();
                    $deletedCount++;
                }
            });

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品を全削除しました。削除件数 '.$deletedCount.' 件');
    }

    public function destroy(AuctionItem $auctionItem)
    {
        $this->authorizeOwner($auctionItem);
        $this->deleteAuctionItemImages($auctionItem);
        $this->deleteAuctionItemImage($auctionItem->image_path, $auctionItem->id);
        $this->deleteAuctionItemImage($auctionItem->sold_image_path, $auctionItem->id);
        $auctionItem->delete();

        return redirect()
            ->route('auction-items.index')
            ->with('success', '商品を削除しました。');
    }

    public function deleteDuplicates(Request $request)
    {
        if (! $request->filled('delete_mode')) {
            $request->merge(['delete_mode' => 'selected']);
        }

        $validated = $request->validate([
            'delete_mode' => ['nullable', 'string', Rule::in(['selected', 'latest', 'all_latest'])],
            'keep_item_id' => [
                'required_if:delete_mode,selected',
                'nullable',
                'integer',
                Rule::exists('auction_items', 'id')->where(fn ($query) => $query->where('user_id', Auth::id())),
            ],
            'duplicate_key' => ['required_if:delete_mode,latest', 'nullable', 'string', 'max:600'],
        ]);

        $deleteMode = $validated['delete_mode'] ?? 'selected';
        $duplicateGroups = $this->duplicateAuctionItemGroups();

        if ($deleteMode === 'all_latest') {
            $deleteCount = 0;

            foreach ($duplicateGroups as $group) {
                $keepItemId = $this->latestDuplicateItem($group['items'])->id;
                $deleteCount += $this->deleteDuplicateGroupExcept($group['items'], $keepItemId);
            }

            return redirect()
                ->route('auction-items.duplicates')
                ->with('success', 'すべての重複候補を削除しました。削除 '.$deleteCount.' 件');
        }

        if ($deleteMode === 'latest') {
            $duplicateKey = (string) $validated['duplicate_key'];
            $targetGroup = $duplicateGroups->first(fn ($group) => $group['key'] === $duplicateKey);

            if (! $targetGroup) {
                return redirect()
                    ->route('auction-items.duplicates')
                    ->with('error', '選択した重複候補は見つかりませんでした。');
            }

            $keepItemId = $this->latestDuplicateItem($targetGroup['items'])->id;
        } else {
            $keepItemId = (int) $validated['keep_item_id'];
            $targetGroup = $duplicateGroups->first(
                fn ($group) => $group['items']->contains(fn (AuctionItem $item) => $item->id === $keepItemId)
            );
        }

        if (! $targetGroup) {
            return redirect()
                ->route('auction-items.duplicates')
                ->with('error', '選択した商品は重複候補ではありません。');
        }

        $deleteCount = $this->deleteDuplicateGroupExcept($targetGroup['items'], $keepItemId);

        return redirect()
            ->route('auction-items.duplicates')
            ->with('success', '重複候補を削除しました。削除 '.$deleteCount.' 件');
    }

    private function validateAuctionItem(Request $request, ?AuctionItem $auctionItem = null): array
    {
        $uniqueRule = Rule::unique('auction_items', 'management_id')
            ->where(fn ($query) => $query->where('user_id', Auth::id()));

        if ($auctionItem) {
            $uniqueRule->ignore($auctionItem->id);
        }

        return $request->validate([
            'management_id' => ['required', 'string', 'max:255', $uniqueRule],
            'title' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
            'platform' => ['required', 'string', Rule::in(AuctionItem::PLATFORMS)],
            'purchase_price' => ['nullable', 'integer', 'min:0'],
            'sold_price' => ['nullable', 'integer', 'min:0'],
            'shipping_fee' => ['nullable', 'integer', 'min:0'],
            'sales_fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->whereNotNull('parent_id')),
            ],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KILOBYTES],
            'camera_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KILOBYTES],
            'images' => ['nullable', 'array', 'max:'.self::IMAGE_MAX_COUNT],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KILOBYTES],
        ], [
            'management_id.unique' => 'この管理IDは既に登録されています。別の管理IDを入力してください。',
            'platform.in' => '出品先を選択してください。',
        ]);
    }

    private function ensureFreeAuctionItemLimit(Request $request)
    {
        $user = $request->user();

        if (! $user || $user->hasActiveSubscription()) {
            return null;
        }

        $itemCount = AuctionItem::query()
            ->where('user_id', $user->id)
            ->count();

        if ($itemCount < User::FREE_AUCTION_ITEM_LIMIT) {
            return null;
        }

        return redirect()
            ->route('subscriptions.index')
            ->with('error', 'Freeプランの商品登録は'.User::FREE_AUCTION_ITEM_LIMIT.'件までです。Premiumは7日間無料お試し後、月額'.number_format((int) config('services.stripe.subscription_amount')).'円（税込）で商品登録数の制限がなくなります。')
            ->with('upgrade_title', '商品登録数の上限に達しました。')
            ->with('upgrade_description', '7日間無料お試しで、商品登録数の制限解除、CSV登録、売上分析を実際のフリマ販売データで確認できます。')
            ->with('upgrade_features', $this->premiumUpgradeFeatures());
    }

    private function ensureFreeCategoryLimit(Request $request, mixed $categoryId, ?AuctionItem $auctionItem = null)
    {
        $user = $request->user();
        $categoryId = is_numeric($categoryId) ? (int) $categoryId : null;

        if (! $user || $user->hasActiveSubscription() || $categoryId === null) {
            return null;
        }

        $categoryIsAlreadyUsed = AuctionItem::query()
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->when($auctionItem, fn ($query) => $query->where('id', '!=', $auctionItem->id))
            ->exists();

        if ($categoryIsAlreadyUsed) {
            return null;
        }

        $usedCategoryCount = AuctionItem::query()
            ->where('user_id', $user->id)
            ->whereNotNull('category_id')
            ->when($auctionItem, fn ($query) => $query->where('id', '!=', $auctionItem->id))
            ->distinct('category_id')
            ->count('category_id');

        if ($usedCategoryCount < User::FREE_CATEGORY_LIMIT) {
            return null;
        }

        return redirect()
            ->route('subscriptions.index')
            ->with('error', 'Freeプランで利用できるカテゴリは'.User::FREE_CATEGORY_LIMIT.'件までです。Premiumは7日間無料お試し後、月額'.number_format((int) config('services.stripe.subscription_amount')).'円（税込）でカテゴリ数の制限がなくなります。')
            ->with('upgrade_title', 'カテゴリ数の上限に達しました。')
            ->with('upgrade_description', '7日間無料お試しで、カテゴリ数の制限解除とジャンル別売上分析を確認できます。売れるジャンル、残りやすいジャンルを見ながら仕入れ判断に使えます。')
            ->with('upgrade_features', $this->premiumUpgradeFeatures());
    }

    /**
     * @return array<int, string>
     */
    private function premiumUpgradeFeatures(): array
    {
        return [
            '商品登録数の制限なし',
            'カテゴリ数の制限なし',
            'CSV登録・CSV変換登録',
            '売上分析・CSV出力',
            'ジャンル別売上分析',
            '重複チェック',
        ];
    }

    private function auctionItemImageFile(Request $request): ?UploadedFile
    {
        if ($request->hasFile('camera_image')) {
            return $request->file('camera_image');
        }

        if ($request->hasFile('image')) {
            return $request->file('image');
        }

        return null;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function auctionItemImageFiles(Request $request): array
    {
        $imageFiles = array_values(array_filter(
            $request->file('images', []),
            fn (mixed $file): bool => $file instanceof UploadedFile
        ));

        if ($imageFiles !== []) {
            return $imageFiles;
        }

        $legacyImageFile = $this->auctionItemImageFile($request);

        return $legacyImageFile ? [$legacyImageFile] : [];
    }

    /**
     * @param  array<int, UploadedFile>  $imageFiles
     */
    private function ensureAuctionItemImageLimit(array $imageFiles): void
    {
        if (count($imageFiles) <= self::IMAGE_MAX_COUNT) {
            return;
        }

        throw ValidationException::withMessages([
            'images' => '商品画像は最大'.self::IMAGE_MAX_COUNT.'枚まで登録できます。',
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $imageFiles
     */
    private function storeAuctionItemImages(AuctionItem $auctionItem, array $imageFiles): void
    {
        foreach ($imageFiles as $position => $imageFile) {
            $imagePath = $imageFile->store('auction-items', 'public');
            $auctionItem->images()->create([
                'path' => $imagePath,
                'position' => $position,
            ]);
        }

        $auctionItem->update([
            'image_path' => $auctionItem->images()->value('path'),
        ]);
    }

    private function csvImportError(string $message)
    {
        return redirect()
            ->route('auction-items.index')
            ->with('error', $message);
    }

    private function openUploadedCsv(Request $request, string $field)
    {
        $filePath = $request->file($field)?->getRealPath();

        if (! $filePath || ! file_exists($filePath)) {
            return $this->csvImportError('CSVファイルを読み込めませんでした。');
        }

        $csvText = file_get_contents($filePath);

        if ($csvText === false || trim($csvText) === '') {
            return $this->csvImportError('CSVファイルが空です。');
        }

        $encoding = mb_detect_encoding($csvText, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932', 'EUC-JP'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $csvText = mb_convert_encoding($csvText, 'UTF-8', $encoding);
        }

        $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);
        $handle = fopen('php://temp', 'r+');

        if (! $handle) {
            return $this->csvImportError('CSVファイルを処理できませんでした。');
        }

        fwrite($handle, $csvText);
        rewind($handle);

        return $handle;
    }

    private function authorizeOwner(AuctionItem $auctionItem): void
    {
        if ($auctionItem->user_id !== Auth::id()) {
            abort(403);
        }
    }

    private function parentCategories()
    {
        return Category::query()
            ->with('children.children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, int>
     */
    private function categoryAndDescendantIds(int $categoryId): Collection
    {
        $allCategoryIds = collect([$categoryId]);
        $parentIds = collect([$categoryId]);

        while ($parentIds->isNotEmpty()) {
            $childIds = Category::query()->whereIn('parent_id', $parentIds)->pluck('id');
            $allCategoryIds = $allCategoryIds->merge($childIds);
            $parentIds = $childIds;
        }

        return $allCategoryIds->unique()->values();
    }

    private function duplicateAuctionItemGroups()
    {
        $items = AuctionItem::query()
            ->where('user_id', Auth::id())
            ->orderBy('platform')
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        $managementIdGroups = $items
            ->groupBy(fn (AuctionItem $item) => $this->duplicateAuctionItemManagementIdKey($item))
            ->filter(fn ($groupItems, string $key) => $key !== '' && $groupItems->count() > 1)
            ->map(fn ($groupItems, string $key) => [
                'key' => 'management_id|'.$key,
                'reason' => '管理ID',
                'title' => $groupItems->first()->title,
                'platform' => '複数',
                'items' => $groupItems
                    ->sortByDesc(fn (AuctionItem $item) => $this->latestDuplicateSortValue($item))
                    ->values(),
            ]);

        $managementIdGroupSignatures = $managementIdGroups
            ->map(fn (array $group) => $this->duplicateGroupSignature($group['items']))
            ->all();

        $titleGroups = $items
            ->groupBy(fn (AuctionItem $item) => $this->duplicateAuctionItemTitleKey($item))
            ->filter(fn ($items, string $key) => $key !== '' && $items->count() > 1)
            ->reject(fn ($items) => in_array($this->duplicateGroupSignature($items), $managementIdGroupSignatures, true))
            ->map(fn ($items, string $key) => [
                'key' => $key,
                'reason' => '出品先 + 商品タイトル',
                'title' => $items->first()->title,
                'platform' => $items->first()->platform,
                'items' => $items
                    ->sortByDesc(fn (AuctionItem $item) => $this->latestDuplicateSortValue($item))
                    ->values(),
            ]);

        return $managementIdGroups
            ->concat($titleGroups)
            ->values();
    }

    private function latestDuplicateItem($items): AuctionItem
    {
        return $items
            ->sortByDesc(fn (AuctionItem $item) => $this->latestDuplicateSortValue($item))
            ->first();
    }

    private function latestDuplicateSortValue(AuctionItem $item): int
    {
        return (($item->created_at?->timestamp ?? 0) * 1000000) + $item->id;
    }

    private function deleteDuplicateGroupExcept($items, int $keepItemId): int
    {
        $deleteItems = $items
            ->reject(fn (AuctionItem $item) => $item->id === $keepItemId)
            ->values();

        foreach ($deleteItems as $item) {
            $this->deleteAuctionItemImages($item);
            $this->deleteAuctionItemImage($item->image_path, $item->id);
            $this->deleteAuctionItemImage($item->sold_image_path, $item->id);
            $item->delete();
        }

        return $deleteItems->count();
    }

    private function duplicateGroupSignature($items): string
    {
        return $items
            ->map(fn (AuctionItem $item) => $item->id)
            ->sort()
            ->values()
            ->implode('|');
    }

    private function duplicateAuctionItemManagementIdKey(AuctionItem $item): string
    {
        return $this->normalizeDuplicateText($item->management_id);
    }

    private function duplicateAuctionItemTitleKey(AuctionItem $item): string
    {
        $title = $this->normalizeDuplicateText($item->title);
        $platform = $this->normalizeDuplicateText($item->platform);

        if ($title === '') {
            return '';
        }

        return $platform.'|'.$title;
    }

    private function normalizeDuplicateText(?string $value): string
    {
        $value = mb_convert_kana(trim((string) $value), 'asKV');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    private function resolveCsvCategoryId(mixed $parentName, mixed $childName): ?int
    {
        $parentName = trim((string) $parentName);
        $childName = trim((string) $childName);

        if ($parentName === '' || $childName === '') {
            return null;
        }

        return Category::query()
            ->where('name', $childName)
            ->whereHas('parent', fn ($query) => $query->where('name', $parentName))
            ->value('id');
    }

    private function resolveExistingAuctionItemCategoryId(string $title, string $platform, string $managementId = ''): ?int
    {
        $title = trim($title);
        $managementId = trim($managementId);
        $platformValues = AuctionItem::platformFilterValues(AuctionItem::normalizePlatformName($platform));

        $query = AuctionItem::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('category_id')
            ->whereIn('platform', $platformValues)
            ->where(function ($query) use ($managementId, $title) {
                if ($managementId !== '') {
                    $query->where('management_id', $managementId);
                }

                if ($title !== '') {
                    $query->orWhere('title', $title);
                }
            })
            ->orderByRaw('status = ? desc', [AuctionItem::STATUS_SELLING])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return $query->value('category_id');
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<int, string>
     */
    private function normalizeFurugiImportHeaders(array $headers): array
    {
        $aliases = [
            '管理ID' => 'management_id',
            '商品ID' => 'management_id',
            '商品タイトル' => 'title',
            'タイトル' => 'title',
            '大ジャンル' => 'parent_category',
            '小ジャンル' => 'category',
            '出品先' => 'platform',
            'ステータス' => 'status',
            '仕入れ値' => 'purchase_price',
            '仕入値' => 'purchase_price',
            '販売価格' => 'sold_price',
            '売値' => 'sold_price',
            '販売手数料率' => 'sales_fee_rate',
            '販売手数料率（%）' => 'sales_fee_rate',
            '販売手数料率(%)' => 'sales_fee_rate',
            '販売手数料' => 'sales_fee',
            '送料' => 'shipping_fee',
            '実利益' => 'profit',
            'SOLD日' => 'sold_at',
            'sold日' => 'sold_at',
            'コメント' => 'comment',
            '商品画像URL' => 'image_url',
            'SOLD画像URL' => 'sold_image_url',
            '作成日' => 'created_at',
            '更新日' => 'updated_at',
        ];

        return array_map(function ($header) use ($aliases) {
            $header = trim((string) $header);
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

            return $aliases[$header] ?? $header;
        }, $headers);
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        $value = trim((string) $value);
        $value = str_replace(["\0", "\r"], ['', "\n"], $value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return mb_substr($value, 0, self::CSV_MAX_CELL_LENGTH);
    }

    private function isImportableYahooAuctionSale(array $data): bool
    {
        $managementId = trim((string) ($data['商品ID'] ?? ''));
        $title = trim((string) ($data['取扱内容'] ?? ''));
        $status = trim((string) ($data['状態'] ?? ''));

        return $managementId !== ''
            && $managementId !== '-'
            && $title !== ''
            && $status === self::YAHOO_AUCTION_SALE_STATUS;
    }

    private function parseYahooAuctionSalesFee(array $data): int
    {
        $successfulBidFee = $this->parseCsvMoney($data['落札システム利用料'] ?? null);
        $salesFee = $this->parseCsvMoney($data['販売手数料'] ?? null);

        return $successfulBidFee + $salesFee;
    }

    private function buildYahooAuctionComment(array $data): ?string
    {
        $parts = [
            'ヤフオク売上CSVから変換',
            '状態: '.trim((string) ($data['状態'] ?? '')),
            '売上: '.trim((string) ($data['売上'] ?? '')),
            '決済金額: '.trim((string) ($data['決済金額'] ?? '')),
            '受取金額: '.trim((string) ($data['受取金額'] ?? '')),
        ];

        $comment = implode(' / ', array_filter($parts, fn ($part) => ! str_ends_with($part, ': ')));

        return $comment !== '' ? mb_substr($comment, 0, self::CSV_MAX_CELL_LENGTH) : null;
    }

    private function isImportableMercariShopsSale(array $data): bool
    {
        $managementId = $this->mercariShopsManagementId($data);
        $title = trim((string) ($data['商品名'] ?? ''));
        $canceledAt = trim((string) ($data['キャンセル日'] ?? ''));
        $soldPrice = $this->parseCsvMoney($data['売上（税込）'] ?? null);

        return $managementId !== ''
            && $title !== ''
            && $canceledAt === ''
            && $soldPrice > 0;
    }

    private function mercariShopsManagementId(array $data): string
    {
        $orderNumber = trim((string) ($data['注文番号'] ?? ''));
        $detailNumber = trim((string) ($data['明細番号'] ?? ''));
        $managementId = trim($orderNumber.'-'.$detailNumber, '-');

        return mb_substr($managementId, 0, 255);
    }

    private function parseMercariShopsShippingFee(array $data): int
    {
        $mercariShippingFee = $this->parseCsvMoney($data['メルカリ便送料（税込）'] ?? null);
        $shippingFee = $this->parseCsvMoney($data['送料（税込）'] ?? null);

        return $mercariShippingFee + $shippingFee;
    }

    private function buildMercariShopsComment(array $data): ?string
    {
        $parts = [
            'メルカリShops CSVから変換',
            '注文番号: '.trim((string) ($data['注文番号'] ?? '')),
            '明細番号: '.trim((string) ($data['明細番号'] ?? '')),
            '明細種別: '.trim((string) ($data['明細種別'] ?? '')),
            'ショップ名: '.trim((string) ($data['ショップ名'] ?? '')),
            '販売利益: '.trim((string) ($data['販売利益'] ?? '')),
        ];

        $comment = implode(' / ', array_filter($parts, fn ($part) => ! str_ends_with($part, ': ')));

        return $comment !== '' ? mb_substr($comment, 0, self::CSV_MAX_CELL_LENGTH) : null;
    }

    private function parseCsvMoney(mixed $value): int
    {
        $value = mb_convert_kana(trim((string) $value), 'n');

        if ($value === '' || $value === '-') {
            return 0;
        }

        $normalized = preg_replace('/[^\d.-]/', '', $value) ?? '';

        if ($normalized === '' || $normalized === '-') {
            return 0;
        }

        return max(0, (int) round((float) $normalized));
    }

    private function parseCsvRate(mixed $value): ?float
    {
        $value = mb_convert_kana(trim((string) $value), 'n');

        if ($value === '' || $value === '-') {
            return null;
        }

        $normalized = preg_replace('/[^\d.-]/', '', $value) ?? '';

        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        return max(0, min(100, (float) $normalized));
    }

    private function normalizePlatform(mixed $platform): string
    {
        $platform = AuctionItem::normalizePlatformName($platform);
        $platform = str_replace(["\0", "\r", "\n", "\t"], '', $platform);
        $platform = mb_substr($platform, 0, 50);

        if ($platform === '' || preg_match('/^[=+\-@]/', $platform) === 1) {
            return AuctionItem::PLATFORM_OTHER;
        }

        return in_array($platform, AuctionItem::PLATFORMS, true)
            ? $platform
            : AuctionItem::PLATFORM_OTHER;
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = trim((string) $status);

        $status = match ($status) {
            'SOLD', '売却済み', '売却済', '販売済み', '販売済' => AuctionItem::STATUS_SOLD,
            '出品中', '販売中' => AuctionItem::STATUS_SELLING,
            '下書き', '下書' => AuctionItem::STATUS_DRAFT,
            default => $status,
        };

        return in_array($status, AuctionItem::STATUSES, true)
            ? $status
            : AuctionItem::STATUS_SELLING;
    }

    private function deleteAuctionItemImage(?string $path, ?int $currentAuctionItemId = null): void
    {
        if ($this->isSafeAuctionItemImagePath($path) && ! $this->isAuctionItemImageReferenced($path, $currentAuctionItemId)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function deleteAuctionItemImages(AuctionItem $auctionItem): void
    {
        $imagePaths = $auctionItem->images()->pluck('path')->all();
        $auctionItem->images()->delete();

        foreach (array_unique($imagePaths) as $imagePath) {
            $this->deleteAuctionItemImage($imagePath, $auctionItem->id);
        }
    }

    private function isAuctionItemImageReferenced(string $path, ?int $currentAuctionItemId): bool
    {
        $itemReferenceExists = AuctionItem::query()
            ->when($currentAuctionItemId !== null, fn ($query) => $query->whereKeyNot($currentAuctionItemId))
            ->where(function ($query) use ($path): void {
                $query
                    ->where('image_path', $path)
                    ->orWhere('sold_image_path', $path);
            })
            ->exists();

        if ($itemReferenceExists) {
            return true;
        }

        return AuctionItemImage::query()
            ->where('path', $path)
            ->when($currentAuctionItemId !== null, fn ($query) => $query->where('auction_item_id', '!=', $currentAuctionItemId))
            ->exists();
    }

    private function isSafeAuctionItemImagePath(?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, 'auction-items/')
            && ! str_contains($path, '..')
            && preg_match('/\Aauction-items\/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp|gif)\z/i', $path) === 1;
    }

    private function defaultSalesFeeRate(string $platform): float
    {
        return AuctionItem::SALES_FEE_RATES[$platform] ?? AuctionItem::SALES_FEE_RATES[AuctionItem::PLATFORM_OTHER];
    }

    private function calculateSalesFee(int $soldPrice, float $salesFeeRate): int
    {
        return (int) round($soldPrice * ($salesFeeRate / 100));
    }

    private function calculateProfit(int $soldPrice, int $purchasePrice, int $salesFee, int $shippingFee): int
    {
        return $soldPrice - $purchasePrice - $salesFee - $shippingFee;
    }

    private function parseCsvSoldAt(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $normalized = str_replace(['年', '月', '日', '時', '分'], ['-', '-', '', ':', ''], $value);
            $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;

            try {
                return Carbon::parse($normalized);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function createSoldImage(string $imagePath, ?string $oldSoldImagePath = null): ?string
    {
        if (
            ! function_exists('imagecreatefromjpeg') ||
            ! function_exists('imagejpeg') ||
            ! function_exists('imagesx') ||
            ! function_exists('imagesy')
        ) {
            return null;
        }

        if ($oldSoldImagePath) {
            $this->deleteAuctionItemImage($oldSoldImagePath);
        }

        if (! $this->isSafeAuctionItemImagePath($imagePath)) {
            return null;
        }

        $fullPath = Storage::disk('public')->path($imagePath);

        if (! file_exists($fullPath)) {
            return null;
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $image = match ($extension) {
            'jpg', 'jpeg' => \imagecreatefromjpeg($fullPath),
            'png' => function_exists('imagecreatefrompng') ? \imagecreatefrompng($fullPath) : null,
            'webp' => function_exists('imagecreatefromwebp') ? \imagecreatefromwebp($fullPath) : null,
            'gif' => function_exists('imagecreatefromgif') ? \imagecreatefromgif($fullPath) : null,
            default => null,
        };

        if (! $image) {
            return null;
        }

        $width = \imagesx($image);
        $height = \imagesy($image);

        \imagealphablending($image, true);
        \imagesavealpha($image, true);

        $overlay = \imagecolorallocatealpha($image, 220, 38, 38, 25);
        \imagefilledrectangle($image, 0, (int) ($height * 0.36), $width, (int) ($height * 0.64), $overlay);

        $white = \imagecolorallocate($image, 255, 255, 255);
        $fontPath = public_path('fonts/NotoSansJP-Bold.ttf');

        if (file_exists($fontPath) && function_exists('imagettfbbox') && function_exists('imagettftext')) {
            $fontSize = max(32, (int) ($width / 7));
            $text = 'SOLD';
            $box = \imagettfbbox($fontSize, -12, $fontPath, $text);
            $textWidth = abs($box[4] - $box[0]);
            $textHeight = abs($box[5] - $box[1]);
            $x = (int) (($width - $textWidth) / 2);
            $y = (int) (($height + $textHeight) / 2);

            \imagettftext($image, $fontSize, -12, $x, $y, $white, $fontPath, $text);
        } else {
            $text = 'SOLD';
            $font = 5;
            $textWidth = \imagefontwidth($font) * strlen($text);
            $textHeight = \imagefontheight($font);
            $x = (int) (($width - $textWidth) / 2);
            $y = (int) (($height - $textHeight) / 2);

            \imagestring($image, $font, $x, $y, $text, $white);
        }

        $soldImagePath = 'auction-items/sold_'.uniqid('', true).'.jpg';
        $soldFullPath = Storage::disk('public')->path($soldImagePath);

        \imagejpeg($image, $soldFullPath, 90);
        \imagedestroy($image);

        return $soldImagePath;
    }
}
