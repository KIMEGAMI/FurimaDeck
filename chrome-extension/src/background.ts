import { adapterForUrl } from './adapters';
import { platforms, type PlatformId } from './platforms';
import { prefillMarketplaceForm, type MarketplaceImage, type MarketplacePrefillValues } from './mercari-prefill';
import { hasPrivacyConsent } from './privacy-consent';

const PLATFORM_IDS = new Set<PlatformId>(['mercari', 'rakuma', 'yahoo_flea_market', 'yahoo_auctions', 'mercari_shops']);
const PENDING_LAUNCH_TTL_MS = 10 * 60 * 1000;
const MAX_IMAGE_COUNT = 10;
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

type ExtensionSession = { baseUrl?: unknown; accessToken?: unknown };
type PendingMarketplacePrefill = {
    tabId: number;
    platformId: PlatformId;
    expiresAt: number;
    ready: boolean;
    values?: MarketplacePrefillValues;
};

chrome.runtime.onInstalled.addListener(() => {
    // Marketplace pages are touched only after the user selects that site in FurimaDeck.
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
    if (changeInfo.status !== 'complete' || !tab.url) return;
    void runPendingMarketplacePrefill(tabId, tab.url);
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.type === 'FURIMADECK_PRODUCT_LINK_CLICK') {
        void handleProductLinkClick(message, sender).then(sendResponse).catch(() => sendResponse({ saved: false }));
        return true;
    }

    if (message.type === 'FURIMADECK_CONSUME_PENDING_LISTING_LAUNCH') {
        void consumePendingListingLaunch(message).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }

    if (message.type !== 'FURIMADECK_INSPECT_ACTIVE_TAB') return;

    void inspectActiveTab().then(sendResponse).catch(() => sendResponse({ hostname: null }));
    return true;
});

async function handleProductLinkClick(
    message: Record<string, unknown>,
    sender: { url?: string; tab?: { id?: number; url?: string } },
): Promise<{ saved: boolean; opened?: boolean }> {
    const platformId = String(message.platformId) as PlatformId;
    const senderUrl = sender.tab?.url ?? sender.url;
    const marketplace = platforms.find((platform) => platform.id === platformId);
    if (!marketplace?.listingUrl || !senderUrl) {
        return { saved: false };
    }

    const sourceUrl = new URL(senderUrl);
    const { connectedBaseUrl } = await chrome.storage.local.get(['connectedBaseUrl']);
    if (typeof connectedBaseUrl !== 'string' || sourceUrl.origin !== connectedBaseUrl) return { saved: false };

    if (!(await hasPrivacyConsent())) {
        await chrome.tabs.create({ url: marketplace.listingUrl });
        return { saved: false, opened: true };
    }

    const productId = Number(message.productId);
    if (!Number.isSafeInteger(productId) || productId < 1 || !PLATFORM_IDS.has(platformId)) {
        return { saved: false };
    }

    return openMarketplaceFromProduct(sourceUrl.origin, productId, platformId);
}

async function openMarketplaceFromProduct(baseUrl: string, productId: number, platformId: PlatformId): Promise<{ saved: boolean; opened: boolean }> {
    const marketplace = platforms.find((platform) => platform.id === platformId);
    if (!marketplace?.listingUrl) return { saved: false, opened: false };

    const permittedOrigins = marketplace.formPermissionOrigins ?? [];
    const canPrefill = permittedOrigins.length > 0 && await chrome.permissions.contains({ origins: permittedOrigins });

    const { furimadeckSession } = await chrome.storage.session.get(['furimadeckSession']);
    const session = furimadeckSession as ExtensionSession | undefined;
    if (!canPrefill || typeof session?.accessToken !== 'string' || session.baseUrl !== baseUrl) {
        await savePendingListingLaunch(productId, platformId);
        await chrome.tabs.create({ url: marketplace.listingUrl });
        return { saved: true, opened: true };
    }

    const tab = await chrome.tabs.create({ url: 'about:blank', active: true });
    if (!Number.isSafeInteger(tab.id)) return { saved: false, opened: false };
    const tabId = tab.id as number;
    const pending: PendingMarketplacePrefill = { tabId, platformId, ready: false, expiresAt: Date.now() + PENDING_LAUNCH_TTL_MS };
    await chrome.storage.session.remove(['pendingListingLaunch']);
    await chrome.storage.session.set({ pendingMarketplacePrefill: pending });
    await chrome.tabs.update(tabId, { url: marketplace.listingUrl });

    try {
        const response = await fetch(`${baseUrl}/api/extension/v1/products/${productId}/draft?platform=${encodeURIComponent(platformId)}`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${session.accessToken}` },
        });
        if (!response.ok) throw new Error('Draft request failed');
        const payload = await response.json();
        const values = await extractMarketplacePrefillValues(payload, baseUrl, productId, platformId, session.accessToken);
        await chrome.storage.session.set({ pendingMarketplacePrefill: { ...pending, ready: true, values } });
        const currentTab = await chrome.tabs.get(tabId).catch(() => null);
        if (currentTab?.status === 'complete' && currentTab.url) {
            await runPendingMarketplacePrefill(tabId, currentTab.url);
        }
    } catch {
        await chrome.storage.session.remove(['pendingMarketplacePrefill']);
        await savePendingListingLaunch(productId, platformId);
        await chrome.storage.session.set({ marketplacePrefillStatus: '商品情報を取得できませんでした。出品ページを開きましたが、自動入力は行っていません。' });
    }

    return { saved: true, opened: true };
}

async function extractMarketplacePrefillValues(payload: unknown, baseUrl: string, productId: number, platformId: PlatformId, accessToken: string): Promise<MarketplacePrefillValues> {
    if (typeof payload !== 'object' || payload === null || !('product' in payload)) {
        throw new Error('Invalid draft response');
    }
    const product = payload.product;
    if (typeof product !== 'object' || product === null || !('title' in product) || !('description' in product) || !('price' in product)) {
        throw new Error('Invalid product draft');
    }
    const title = product.title;
    const description = product.description;
    const price = product.price;
    if (typeof title !== 'string' || title.trim() === '') throw new Error('Invalid product title');
    if (description !== null && typeof description !== 'string') throw new Error('Invalid product description');
    if (price !== null && (typeof price !== 'number' || !Number.isSafeInteger(price) || price < 0)) throw new Error('Invalid product price');
    const imageTransfer = await fetchMarketplaceImages(product.images, baseUrl, productId, accessToken);
    const attributes = Array.isArray(product.attributes)
        ? product.attributes.flatMap((attribute): { label: string; value: string }[] => {
            if (typeof attribute !== 'object' || attribute === null || !('label' in attribute) || !('value' in attribute)) return [];
            return typeof attribute.label === 'string' && typeof attribute.value === 'string' && attribute.label.trim() && attribute.value.trim()
                ? [{ label: attribute.label, value: attribute.value }]
                : [];
        })
        : [];
    return {
        platformId,
        title,
        description,
        price,
        category: typeof product.category === 'string' ? product.category : null,
        condition: typeof product.condition === 'string' ? product.condition : null,
        shippingMethod: typeof product.shipping_method === 'string' ? product.shipping_method : null,
        shippingFee: typeof product.shipping_fee === 'number' && Number.isSafeInteger(product.shipping_fee) ? product.shipping_fee : null,
        shippingPayer: typeof product.shipping_payer === 'string' ? product.shipping_payer : null,
        senderRegion: typeof product.sender_region === 'string' ? product.sender_region : null,
        dispatchDays: typeof product.dispatch_days === 'number' && Number.isSafeInteger(product.dispatch_days) ? product.dispatch_days : null,
        shippingSize: typeof product.shipping_size === 'string' ? product.shipping_size : null,
        shippingWeightGrams: typeof product.shipping_weight_grams === 'number' && Number.isSafeInteger(product.shipping_weight_grams) ? product.shipping_weight_grams : null,
        saleFormat: typeof product.sale_format === 'string' ? product.sale_format : null,
        listingPeriodDays: typeof product.listing_period_days === 'number' && Number.isSafeInteger(product.listing_period_days) ? product.listing_period_days : null,
        returnPolicy: typeof product.return_policy === 'string' ? product.return_policy : null,
        purchaseApplication: typeof product.purchase_application === 'string' ? product.purchase_application : null,
        quantity: typeof product.listing_quantity === 'number' && Number.isSafeInteger(product.listing_quantity) && product.listing_quantity > 0
            ? product.listing_quantity
            : typeof product.quantity_available === 'number' && Number.isSafeInteger(product.quantity_available) && product.quantity_available > 0
                ? product.quantity_available
                : null,
        janEan: typeof product.jan_ean === 'string' ? product.jan_ean : null,
        isbn: typeof product.isbn === 'string' ? product.isbn : null,
        manufacturerModelNumber: typeof product.manufacturer_model_number === 'string' ? product.manufacturer_model_number : null,
        serialNumber: typeof product.serial_number === 'string' ? product.serial_number : null,
        attributes,
        images: imageTransfer.images,
        imageWarning: imageTransfer.omittedCount > 0
            ? `商品画像は全${imageTransfer.totalCount}枚中${imageTransfer.images.length}枚だけ転送しました。未転送の画像は手動で追加してください。`
            : null,
    };
}

async function fetchMarketplaceImages(rawImages: unknown, baseUrl: string, productId: number, accessToken: string): Promise<{ images: MarketplaceImage[]; totalCount: number; omittedCount: number }> {
    if (!Array.isArray(rawImages)) return { images: [], totalCount: 0, omittedCount: 0 };
    const images: MarketplaceImage[] = [];
    let totalBytes = 0;
    let omittedCount = Math.max(0, rawImages.length - MAX_IMAGE_COUNT);
    for (const [index, image] of rawImages.slice(0, MAX_IMAGE_COUNT).entries()) {
        if (typeof image !== 'object' || image === null || !('id' in image) || !Number.isSafeInteger(image.id) || Number(image.id) < 1 || !('path' in image) || typeof image.path !== 'string') {
            omittedCount++;
            continue;
        }
        const expectedPath = `/api/extension/v1/products/${productId}/images/${Number(image.id)}`;
        let url: URL;
        try { url = new URL(image.path, baseUrl); } catch { omittedCount++; continue; }
        if (url.origin !== baseUrl || url.pathname !== expectedPath || url.search !== '') { omittedCount++; continue; }
        const response = await fetch(url, { headers: { Accept: 'image/*', Authorization: `Bearer ${accessToken}` } });
        if (!response.ok) { omittedCount++; continue; }
        const blob = await response.blob();
        if (!blob.type.startsWith('image/')) { omittedCount++; continue; }
        if (totalBytes + blob.size > MAX_IMAGE_BYTES) {
            omittedCount += rawImages.length - index;
            break;
        }
        totalBytes += blob.size;
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        const chunkSize = 0x8000;
        for (let offset = 0; offset < bytes.length; offset += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + chunkSize, bytes.length)));
        }
        const extension = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : blob.type === 'image/gif' ? 'gif' : 'jpg';
        images.push({ name: `furimadeck-${Number(image.id)}.${extension}`, type: blob.type, dataUrl: `data:${blob.type};base64,${btoa(binary)}` });
    }
    return { images, totalCount: rawImages.length, omittedCount };
}

async function savePendingListingLaunch(productId: number, platformId: PlatformId): Promise<void> {
    await chrome.storage.session.set({ pendingListingLaunch: { productId, platformId, expiresAt: Date.now() + PENDING_LAUNCH_TTL_MS } });
}

async function runPendingMarketplacePrefill(tabId: number, url: string): Promise<void> {
    const { pendingMarketplacePrefill } = await chrome.storage.session.get(['pendingMarketplacePrefill']);
    if (!pendingMarketplacePrefill || typeof pendingMarketplacePrefill !== 'object') return;
    const pending = pendingMarketplacePrefill as PendingMarketplacePrefill;
    if (pending.expiresAt < Date.now()) {
        await chrome.storage.session.remove(['pendingMarketplacePrefill']);
        return;
    }
    if (pending.tabId !== tabId || !pending.ready || !pending.values) return;

    const activeUrl = new URL(url);
    const marketplace = platforms.find((platform) => platform.id === pending.platformId);
    if (!marketplace || !marketplace.formPermissionOrigins?.length || activeUrl.origin !== new URL(marketplace.listingUrl as string).origin) return;
    if (!(await chrome.permissions.contains({ origins: marketplace.formPermissionOrigins }))) return;

    await chrome.storage.session.remove(['pendingMarketplacePrefill']);
    try {
        const [execution] = await chrome.scripting.executeScript({
            target: { tabId },
            func: prefillMarketplaceForm,
            args: [pending.values],
        });
        const result = execution?.result as { filled?: string[]; missing?: string[]; ambiguous?: string[]; existing?: string[]; page?: string } | undefined;
        const status = result?.page !== 'listing' ? `${marketplace.name}の出品フォームを確認できませんでした。入力は行っていません。`
            : `入力補助を実行しました。ページ上の通知を確認してください。${marketplace.name}への送信・出品確定は行っていません。`;
        await chrome.storage.session.set({ marketplacePrefillStatus: status });
    } catch {
        await chrome.storage.session.set({ marketplacePrefillStatus: '入力補助を実行できませんでした。ページを再読み込みして確認してください。' });
    }
}

async function consumePendingListingLaunch(message: Record<string, unknown>): Promise<{ productId: number; platformId: PlatformId } | null> {
    if (!(await hasPrivacyConsent())) return null;
    const activeUrl = typeof message.activeUrl === 'string' ? new URL(message.activeUrl) : null;
    const adapter = activeUrl ? adapterForUrl(activeUrl) : null;
    const { pendingListingLaunch } = await chrome.storage.session.get(['pendingListingLaunch']);
    if (!pendingListingLaunch || typeof pendingListingLaunch !== 'object') return null;

    const launch = pendingListingLaunch as { productId?: unknown; platformId?: unknown; expiresAt?: unknown };
    if (typeof launch.expiresAt !== 'number' || launch.expiresAt < Date.now()) {
        await chrome.storage.session.remove(['pendingListingLaunch']);
        return null;
    }
    if (!adapter || launch.platformId !== adapter.id) return null;
    const productId = Number(launch.productId);
    await chrome.storage.session.remove(['pendingListingLaunch']);
    if (!Number.isSafeInteger(productId) || productId < 1) return null;
    return { productId, platformId: adapter.id };
}

async function inspectActiveTab(): Promise<{ hostname: string | null }> {
    if (!(await hasPrivacyConsent())) return { hostname: null };
    const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    if (!tab?.id || !tab.url) return { hostname: null };
    const activeUrl = new URL(tab.url);
    if (!adapterForUrl(activeUrl)) return { hostname: null };
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['content.js'] });
    return await chrome.tabs.sendMessage(tab.id, { type: 'FURIMADECK_READ_HOSTNAME' }) as { hostname?: string };
}
