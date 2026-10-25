export type ManualListingDraft = {
    platformName: string;
    internalSku: string;
    title: string;
    description: string | null;
    price: number | null;
    condition: string;
    category: string | null;
    shippingMethod: string | null;
    shippingFee: number | null;
    salesFeeRate: number | string | null;
    shippingPayer?: string | null;
    senderRegion?: string | null;
    dispatchDays?: number | null;
    shippingSize?: string | null;
    shippingWeightGrams?: number | null;
    saleFormat?: string | null;
    listingPeriodDays?: number | null;
    returnPolicy?: string | null;
    purchaseApplication?: string | null;
    listingQuantity?: number | null;
    attributes?: Array<{ label: string; value: string }>;
};

function displayValue(value: string | number | null): string {
    if (value === null || (typeof value === 'string' && value.trim() === '')) return '未設定';
    return String(value);
}

export function displayListingValue(field: 'condition' | 'shippingPayer' | 'saleFormat' | 'purchaseApplication', value: string | null): string {
    if (value === null || value.trim() === '') return '未設定';
    const labels: Record<typeof field, Record<string, string>> = {
        condition: {
            new: '新品', unused: '未使用', like_new: '未使用に近い', used_good: '目立った傷や汚れなし',
            used: 'やや傷や汚れあり', damaged: '傷や汚れあり', junk: 'ジャンク品',
        },
        shippingPayer: { seller: '出品者負担', buyer: '購入者負担' },
        saleFormat: { fixed_price: '定額販売', auction: 'オークション' },
        purchaseApplication: { enabled: '購入申請あり', disabled: '購入申請なし' },
    };
    return labels[field][value] ?? value;
}

export function formatManualListingDraft(draft: ManualListingDraft): string {
    const fields: Array<[string, string | number | null]> = [
        ['商品ID', draft.internalSku],
        ['タイトル', draft.title],
        ['説明', draft.description],
        ['価格', draft.price === null ? null : `¥${draft.price.toLocaleString('ja-JP')}`],
        ['状態', draft.condition],
        ['カテゴリ', draft.category],
        ...(draft.attributes ?? []).map(({ label, value }) => [label, value] as [string, string]),
        ['配送方法', draft.shippingMethod],
        ['送料', draft.shippingFee === null ? null : `¥${draft.shippingFee.toLocaleString('ja-JP')}`],
        ['送料負担', displayListingValue('shippingPayer', draft.shippingPayer ?? null)],
        ['発送元地域', draft.senderRegion ?? null],
        ['発送までの日数', draft.dispatchDays === null || draft.dispatchDays === undefined ? null : `${draft.dispatchDays}日`],
        ['配送サイズ', draft.shippingSize ?? null],
        ['配送重量', draft.shippingWeightGrams === null || draft.shippingWeightGrams === undefined ? null : `${draft.shippingWeightGrams}g`],
        ['販売形式', displayListingValue('saleFormat', draft.saleFormat ?? null)],
        ['掲載期間', draft.listingPeriodDays === null || draft.listingPeriodDays === undefined ? null : `${draft.listingPeriodDays}日`],
        ['返品方針', draft.returnPolicy ?? null],
        ['購入申請', displayListingValue('purchaseApplication', draft.purchaseApplication ?? null)],
        ['出品数量', draft.listingQuantity ?? null],
        ['販売手数料率', draft.salesFeeRate === null ? null : `${draft.salesFeeRate}%`],
    ];

    return [`出品先: ${draft.platformName}`, ...fields.map(([label, value]) => `${label}\n${displayValue(value)}`)].join('\n\n');
}
