import { detectPlatform, platforms, type PlatformId } from './platforms';
import { adapterForPlatform } from './adapters';
import { displayListingValue, formatManualListingDraft } from './draft';
import { prefillMarketplaceForm, type MarketplaceImage, type MarketplacePrefillValues } from './mercari-prefill';
import { hasPrivacyConsent, PRIVACY_CONSENT_STORAGE_KEY, PRIVACY_CONSENT_VERSION } from './privacy-consent';
import './popup.css';

type Session = { baseUrl: string; accessToken: string; userName: string };
type Draft = {
    product: { id: number; internal_sku: string; product_name: string; title: string; description: string | null; price: number | null; condition: string; category: string | null; shipping_method: string | null; shipping_fee: number | null; shipping_payer: string | null; sender_region: string | null; dispatch_days: number | null; shipping_size: string | null; shipping_weight_grams: number | null; sale_format: string | null; listing_period_days: number | null; return_policy: string | null; purchase_application: string | null; listing_quantity: number | null; sales_fee_rate: number | string | null; quantity_available: number; jan_ean?: string | null; isbn?: string | null; manufacturer_model_number?: string | null; serial_number?: string | null; attributes?: { label: string; value: string }[]; images: { id: number; position: number; path: string }[] };
    platform: { id: PlatformId; name: string; operation_level: string };
    review_fields: string[];
    authorized_publish: boolean;
    listing_history: { id: number; url: string | null; price: number | null; status: string; listed_at: string | null; record_method: string | null }[];
};
type MessageTemplate = { id: number; name: string; purpose: string; body: string };

const byId = <T extends HTMLElement>(id: string): T => {
    const element = document.getElementById(id);
    if (!element) throw new Error(`画面要素が見つかりません: ${id}`);
    return element as T;
};

const connectPanel = byId<HTMLElement>('connect-panel');
const connectedPanel = byId<HTMLElement>('connected-panel');
const privacyConsentPanel = byId<HTMLElement>('privacy-consent');
const featureContent = byId<HTMLElement>('feature-content');
const status = byId<HTMLElement>('status');
const serverInput = byId<HTMLInputElement>('server-url');
const draftPanel = byId<HTMLElement>('draft-panel');
const templateList = byId<HTMLElement>('template-list');
const templateForm = byId<HTMLFormElement>('template-form');
const scopeWarning = byId<HTMLElement>('scope-warning');
const marketplacePermissions = byId<HTMLElement>('marketplace-permissions');
const MAX_PRODUCT_IMAGE_COUNT = 10;
const MAX_PRODUCT_IMAGE_BYTES = 5 * 1024 * 1024;
let session: Session | null = null;
const templatePurposeLabels: Record<string, string> = { greeting: '挨拶', size_answer: 'サイズ回答', shipping: '発送案内', custom: 'その他' };
const themePreferences = ['system', 'dark', 'light'] as const;
let privacyConsentAccepted = false;

async function loadTheme(): Promise<void> {
    const { theme } = await chrome.storage.local.get(['theme']);
    const selectedTheme = themePreferences.includes(theme) ? theme : 'system';
    document.documentElement.dataset.theme = selectedTheme;
}

async function enableFeaturesAfterConsent(): Promise<void> {
    privacyConsentAccepted = await hasPrivacyConsent();
    privacyConsentPanel.hidden = privacyConsentAccepted;
    featureContent.hidden = !privacyConsentAccepted;
    if (!privacyConsentAccepted) return;

    await loadTheme();
    const stored = await chrome.storage.session.get(['furimadeckSession']);
    const candidate = stored.furimadeckSession as Session | undefined;
    if (candidate?.baseUrl && candidate.accessToken) {
        session = candidate;
        renderConnection();
        try {
            const identity = await api<{ user: { name: string }; scopes: string[] }>('/me');
            session.userName = identity.user.name;
            await chrome.storage.session.set({ furimadeckSession: session });
            const requiredScopes = ['products:read', 'drafts:read', 'listings:write', 'templates:read', 'templates:write', 'exports:read', 'activity:read'];
            const missingScopes = requiredScopes.some((scope) => !identity.scopes.includes(scope));
            scopeWarning.hidden = !missingScopes;
            if (!missingScopes) {
                await loadTemplates();
                await refreshMarketplacePermissions();
                try {
                    await registerProductLinkBridge(session.baseUrl);
                } catch {
                    report('FurimaDeckへの接続は有効ですが、商品画面との連携を登録できません。拡張のサイト権限を確認してください。');
                }
                await restorePendingListingLaunch();
            }
            if (missingScopes) report('拡張の権限を更新してください。解除してから再連携してください。');
        } catch {
            await chrome.storage.session.remove(['furimadeckSession']);
            session = null;
            renderConnection();
            report('連携が期限切れです。もう一度連携してください。');
        }
    } else {
        renderConnection();
    }
}

byId<HTMLInputElement>('privacy-consent-check').addEventListener('change', (event) => {
    byId<HTMLButtonElement>('privacy-consent-accept').disabled = !(event.currentTarget as HTMLInputElement).checked;
});
byId<HTMLButtonElement>('privacy-consent-accept').addEventListener('click', async () => {
    if (!byId<HTMLInputElement>('privacy-consent-check').checked) return;
    await chrome.storage.local.set({ [PRIVACY_CONSENT_STORAGE_KEY]: PRIVACY_CONSENT_VERSION });
    privacyConsentPanel.hidden = true;
    featureContent.hidden = false;
    await enableFeaturesAfterConsent();
});

function report(message: string): void {
    status.textContent = message;
}

function normalizeBaseUrl(raw: string): string {
    const url = new URL(raw.trim());
    const localDevelopment = url.protocol === 'http:' && url.hostname === '127.0.0.1';
    const productionHost = url.protocol === 'https:' && url.hostname === 'furimadeck.kimegami.jp';
    if ((!localDevelopment && !productionHost) || url.username || url.password || (productionHost && url.port && url.port !== '443')) {
        throw new Error('登録済みのFurimaDeck URL（本番または127.0.0.1の開発環境）を指定してください。');
    }
    return url.origin;
}

async function grantHostPermission(baseUrl: string): Promise<void> {
    const origin = `${new URL(baseUrl).origin}/*`;
    if (!(await chrome.permissions.request({ origins: [origin] }))) {
        throw new Error('FurimaDeckへの接続権限が必要です。');
    }
}

async function registerProductLinkBridge(baseUrl: string): Promise<void> {
    const origin = new URL(baseUrl).origin;
    try {
        await chrome.scripting.unregisterContentScripts({ ids: ['furimadeck-product-link-bridge'] });
    } catch { /* No prior registration is expected on first pairing. */ }
    await chrome.storage.local.remove(['connectedBaseUrl']);
    await chrome.scripting.registerContentScripts([{
        id: 'furimadeck-product-link-bridge',
        matches: [`${origin}/*`],
        js: ['product-bridge.js'],
        runAt: 'document_idle',
        persistAcrossSessions: true,
    }]);
    await chrome.storage.local.set({ connectedBaseUrl: origin });

    const [activeTab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    if (activeTab?.id && activeTab.url && new URL(activeTab.url).origin === origin) {
        await chrome.scripting.executeScript({ target: { tabId: activeTab.id }, files: ['product-bridge.js'] });
    }
}

async function restorePendingListingLaunch(): Promise<void> {
    if (!session) return;
    const [activeTab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    if (!activeTab?.url) return;

    const launch = await chrome.runtime.sendMessage({
        type: 'FURIMADECK_CONSUME_PENDING_LISTING_LAUNCH',
        activeUrl: activeTab.url,
    }) as { productId?: number; platformId?: PlatformId } | null;
    if (!launch || !Number.isSafeInteger(launch.productId) || !launch.platformId) return;

    await loadDraft(launch.productId as number, launch.platformId);
    report(`${platformName(launch.platformId)}のドラフトを読み込みました。権限許可済みなら入力補助が動作します。許可されていない場合は、出品画面を開くだけです。`);
}

async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    if (!session) throw new Error('FurimaDeckに連携してください。');
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${session.accessToken}`);
    if (init.body) headers.set('Content-Type', 'application/json');
    const response = await fetch(`${session.baseUrl}/api/extension/v1${path}`, {
        ...init,
        headers,
    });
    if (!response.ok) {
        const payload = await response.json().catch(() => ({})) as { message?: string };
        throw new Error(payload.message || `FurimaDeckとの通信に失敗しました（${response.status}）。`);
    }
    return response.json() as Promise<T>;
}

async function downloadCsv(kind: 'products' | 'listings' | 'sales'): Promise<void> {
    if (!session) throw new Error('FurimaDeckに連携してください。');
    const response = await fetch(`${session.baseUrl}/api/extension/v1/exports/${kind}.csv`, {
        headers: { Accept: 'text/csv', Authorization: `Bearer ${session.accessToken}` },
    });
    if (!response.ok) {
        const payload = await response.json().catch(() => ({})) as { message?: string };
        throw new Error(payload.message || `CSV出力に失敗しました（${response.status}）。`);
    }
    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = `furimadeck-${kind}.csv`;
    anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

async function pair(): Promise<boolean> {
    let phase = '接続先URLの確認';
    try {
        const baseUrl = normalizeBaseUrl(serverInput.value);
        phase = 'FurimaDeckへの接続権限の確認';
        await grantHostPermission(baseUrl);
        const verifierBytes = crypto.getRandomValues(new Uint8Array(32));
        const verifier = Array.from(verifierBytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
        const challenge = btoa(String.fromCharCode(...new Uint8Array(digest))).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
        const redirectUri = chrome.identity.getRedirectURL('pair');
        phase = '連携開始APIへの接続';
        const startResponse = await fetch(`${baseUrl}/api/extension/v1/pairing/start`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ code_challenge: challenge, redirect_uri: redirectUri, device_name: 'Chrome拡張（この端末）' }),
        });
        const start = await startResponse.json() as { pairing_id?: string; approval_url?: string; message?: string };
        if (!startResponse.ok || !start.pairing_id || !start.approval_url) throw new Error(start.message || `連携開始APIがHTTP ${startResponse.status}を返しました。`);

        phase = 'FurimaDeck承認画面の表示';
        const callback = await chrome.identity.launchWebAuthFlow({ url: start.approval_url, interactive: true });
        phase = '承認結果の確認';
        const callbackUrl = new URL(callback);
        const callbackParams = new URLSearchParams(callbackUrl.hash.slice(1));
        const pairingId = callbackParams.get('pairing_id');
        const code = callbackParams.get('code');
        if (pairingId !== start.pairing_id || !code) throw new Error('承認完了の戻り値が確認できません。承認画面まで進んだか確認してください。');

        phase = '連携完了APIへの接続';
        const completed = await fetch(`${baseUrl}/api/extension/v1/pairing/complete`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ pairing_id: pairingId, code, verifier }),
        });
        const result = await completed.json() as { access_token?: string; user?: { name: string }; message?: string };
        if (!completed.ok || !result.access_token) throw new Error(result.message || `連携完了APIがHTTP ${completed.status}を返しました。`);

        phase = '連携セッションの保存';
        session = { baseUrl, accessToken: result.access_token, userName: result.user?.name ?? 'FurimaDeckユーザー' };
        await chrome.storage.session.set({ furimadeckSession: session });
        phase = '商品画面との連携設定';
        let bridgeReady = true;
        try {
            await registerProductLinkBridge(baseUrl);
        } catch {
            bridgeReady = false;
        }
        scopeWarning.hidden = true;
        renderConnection();
        await loadTemplates();
        await refreshMarketplacePermissions();
        return bridgeReady;
    } catch (error) {
        const detail = error instanceof Error && error.message.trim() !== '' ? error.message : '原因を特定できないエラーです。';
        throw new Error(`${phase}で失敗しました。${detail}`);
    }
}

function platformName(id: PlatformId): string {
    return platforms.find((platform) => platform.id === id)?.name ?? '出品先';
}

async function refreshMarketplacePermissions(): Promise<void> {
    marketplacePermissions.replaceChildren();
    for (const marketplace of platforms.filter((platform) => platform.listingUrl && platform.formPermissionOrigins?.length)) {
        const permitted = await chrome.permissions.contains({ origins: marketplace.formPermissionOrigins ?? [] });
        const row = document.createElement('div');
        row.className = 'marketplace-permission-row';
        const label = document.createElement('span');
        label.className = 'hint';
        label.textContent = `${marketplace.name}：${permitted ? '入力権限あり' : '出品ページを開くだけ'}`;
        row.append(label);
        if (!permitted) {
            const grant = document.createElement('button');
            grant.type = 'button';
            grant.className = 'quiet';
            grant.id = `grant-marketplace-${marketplace.id}`;
            grant.textContent = `${marketplace.name}の入力権限を許可`;
            grant.addEventListener('click', async () => {
                try {
                    const granted = await chrome.permissions.request({ origins: marketplace.formPermissionOrigins ?? [] });
                    await refreshMarketplacePermissions();
                    report(granted ? `${marketplace.name}の任意権限を許可しました。商品画面から再度選ぶと入力補助を行います。` : '権限は許可されませんでした。公式出品ページを開く機能のみ利用できます。');
                } catch {
                    report('権限を確認できませんでした。拡張のポップアップからもう一度お試しください。');
                }
            });
            row.append(grant);
        }
        marketplacePermissions.append(row);
    }
}

function renderConnection(): void {
    connectPanel.hidden = Boolean(session);
    connectedPanel.hidden = !session;
    if (!session) scopeWarning.hidden = true;
    byId<HTMLElement>('connection-state').textContent = session ? '連携済み' : '未連携';
    byId<HTMLElement>('account-name').textContent = session?.userName ?? '';
    if (session) serverInput.value = session.baseUrl;
}

async function loadTemplates(): Promise<void> {
    if (!session) return;
    templateList.replaceChildren();
    try {
        const result = await api<{ data: MessageTemplate[] }>('/templates');
        if (result.data.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'hint';
            empty.textContent = '登録済みの定型文はありません。';
            templateList.append(empty);
            return;
        }
        for (const template of result.data) {
            const row = document.createElement('div');
            row.className = 'template-row';
            const description = document.createElement('p');
            description.textContent = `${templatePurposeLabels[template.purpose] ?? 'その他'}：${template.name}`;
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'copy';
            copy.textContent = '本文をコピー';
            copy.addEventListener('click', async () => {
                await navigator.clipboard.writeText(template.body);
                report(`「${template.name}」をコピーしました。`);
            });
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'quiet';
            edit.textContent = '編集';
            edit.addEventListener('click', () => {
                byId<HTMLInputElement>('template-name').value = template.name;
                byId<HTMLSelectElement>('template-purpose').value = template.purpose;
                byId<HTMLTextAreaElement>('template-body').value = template.body;
                templateForm.dataset.templateId = String(template.id);
                byId<HTMLButtonElement>('template-save').textContent = '変更を保存';
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'quiet';
            remove.textContent = '削除';
            remove.addEventListener('click', async () => {
                if (!session) return;
                remove.disabled = true;
                try {
                    await api(`/templates/${template.id}`, { method: 'DELETE' });
                    await loadTemplates();
                    report(`「${template.name}」を削除しました。`);
                } catch (error) {
                    report(error instanceof Error ? error.message : '定型文を削除できませんでした。');
                    remove.disabled = false;
                }
            });
            row.append(description, copy, edit, remove);
            templateList.append(row);
        }
    } catch (error) {
        report(error instanceof Error ? error.message : '定型文を読み込めませんでした。');
    }
}

async function loadDraft(productId: number, platformId: PlatformId): Promise<void> {
    draftPanel.dataset.productId = String(productId);
    draftPanel.hidden = false;
    draftPanel.replaceChildren();
    report('ドラフトを準備中…');
    try {
        const draft = await api<Draft>(`/products/${productId}/draft?platform=${encodeURIComponent(platformId)}`);
        renderDraft(draft);
        report('内容を確認し、必要な項目を手動で補ってください。');
    } catch (error) {
        report(error instanceof Error ? error.message : 'ドラフトを準備できませんでした。');
    }
}

function addCopyField(label: string, value: string): void {
    const row = document.createElement('div');
    row.className = 'draft-field';
    const title = document.createElement('span');
    title.textContent = label;
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'copy';
    copy.textContent = 'コピー';
    copy.addEventListener('click', async () => {
        await navigator.clipboard.writeText(value);
        report(`${label}をコピーしました。`);
    });
    const text = document.createElement('p');
    text.textContent = value || '未設定';
    row.append(title, copy, text);
    draftPanel.append(row);
}

function renderDraft(draft: Draft): void {
    const prepared = adapterForPlatform(draft.platform.id).prepareDraft({
        platformId: draft.platform.id,
        title: draft.product.title,
        description: draft.product.description,
        price: draft.product.price,
        condition: draft.product.condition,
        category: draft.product.category,
        shippingMethod: draft.product.shipping_method,
        shippingFee: draft.product.shipping_fee,
        reviewFields: draft.review_fields,
    });
    draftPanel.replaceChildren();
    const heading = document.createElement('h2');
    heading.textContent = `${prepared.platform.name}向けドラフト`;
    const productHeading = document.createElement('p');
    productHeading.className = 'product-heading';
    productHeading.textContent = draft.product.product_name;
    const productIdentifier = document.createElement('p');
    productIdentifier.className = 'product-identifier';
    productIdentifier.textContent = `商品ID: ${draft.product.internal_sku}`;
    draftPanel.append(heading, productHeading, productIdentifier);

    const marketplaceHeading = document.createElement('h3');
    marketplaceHeading.textContent = '主要フリマの出品ページ';
    const marketplaceActions = document.createElement('div');
    marketplaceActions.className = 'marketplace-actions';
    for (const marketplace of platforms.filter((platform) => platform.listingUrl)) {
        const openMarketplace = document.createElement('button');
        openMarketplace.type = 'button';
        openMarketplace.className = 'quiet marketplace-action';
        openMarketplace.textContent = `${marketplace.name}を開く ↗`;
        openMarketplace.addEventListener('click', () => {
            if (marketplace.listingUrl) void chrome.tabs.create({ url: marketplace.listingUrl });
        });
        marketplaceActions.append(openMarketplace);
    }
    draftPanel.append(marketplaceHeading, marketplaceActions);

    if (prepared.platform.listingUrl) {
        const experimentNotice = document.createElement('p');
        experimentNotice.className = 'warning';
        experimentNotice.textContent = '試作機能です。各社の許可は確認できていません（ラクマは公式サポートからツール入力を控えるよう案内されています）。入力済み欄・候補が曖昧な欄は変更せず、入力後の確認と出品操作はご自身で行ってください。';
        draftPanel.append(experimentNotice);

        const prefillButton = document.createElement('button');
        prefillButton.type = 'button';
        prefillButton.className = 'primary';
        prefillButton.textContent = `${prepared.platform.name}の入力欄に反映（試作）`;
        prefillButton.addEventListener('click', () => void prefillMarketplaceListingForm(draft));
        draftPanel.append(prefillButton);
    }

    const copyAll = document.createElement('button');
    copyAll.type = 'button';
    copyAll.className = 'quiet copy-all';
    copyAll.textContent = '出品項目をまとめてコピー';
    copyAll.addEventListener('click', async () => {
        const clipboardText = formatManualListingDraft({
            platformName: prepared.platform.name,
            internalSku: draft.product.internal_sku,
            title: prepared.title,
            description: prepared.description,
            price: prepared.price,
            condition: prepared.condition,
            category: prepared.category,
            shippingMethod: prepared.shippingMethod,
            shippingFee: prepared.shippingFee,
            salesFeeRate: draft.product.sales_fee_rate,
            shippingPayer: draft.product.shipping_payer,
            senderRegion: draft.product.sender_region,
            dispatchDays: draft.product.dispatch_days,
            shippingSize: draft.product.shipping_size,
            shippingWeightGrams: draft.product.shipping_weight_grams,
            saleFormat: draft.product.sale_format,
            listingPeriodDays: draft.product.listing_period_days,
            returnPolicy: draft.product.return_policy,
            purchaseApplication: draft.product.purchase_application,
            listingQuantity: draft.product.listing_quantity,
            attributes: draft.product.attributes,
        });
        await navigator.clipboard.writeText(clipboardText);
        report('出品項目をまとめてコピーしました。公式画面で確認して貼り付けてください。');
    });
    draftPanel.append(copyAll);
    addCopyField('タイトル', draft.product.title);
    addCopyField('説明', draft.product.description ?? '');
    addCopyField('価格', draft.product.price === null ? '' : String(draft.product.price));
    addCopyField('商品ID', draft.product.internal_sku);
    addCopyField('状態', displayListingValue('condition', draft.product.condition));
    addCopyField('カテゴリ', draft.product.category ?? '');
    for (const attribute of draft.product.attributes ?? []) addCopyField(attribute.label, attribute.value);
    if (draft.product.jan_ean) addCopyField('JAN/EAN', draft.product.jan_ean);
    if (draft.product.isbn) addCopyField('ISBN', draft.product.isbn);
    if (draft.product.manufacturer_model_number) addCopyField('型番', draft.product.manufacturer_model_number);
    if (draft.product.serial_number) addCopyField('シリアル番号', draft.product.serial_number);
    addCopyField('配送方法', draft.product.shipping_method ?? '');
    addCopyField('送料', draft.product.shipping_fee === null ? '' : `¥${draft.product.shipping_fee.toLocaleString('ja-JP')}`);
    addCopyField('送料負担', displayListingValue('shippingPayer', draft.product.shipping_payer));
    addCopyField('発送元地域', draft.product.sender_region ?? '');
    addCopyField('発送までの日数', draft.product.dispatch_days === null ? '' : String(draft.product.dispatch_days));
    addCopyField('配送サイズ', draft.product.shipping_size ?? '');
    addCopyField('配送重量（g）', draft.product.shipping_weight_grams === null ? '' : String(draft.product.shipping_weight_grams));
    addCopyField('販売形式', displayListingValue('saleFormat', draft.product.sale_format));
    addCopyField('掲載期間（日）', draft.product.listing_period_days === null ? '' : String(draft.product.listing_period_days));
    addCopyField('返品方針', draft.product.return_policy ?? '');
    addCopyField('購入申請', displayListingValue('purchaseApplication', draft.product.purchase_application));
    addCopyField('出品数量', draft.product.listing_quantity === null ? '' : String(draft.product.listing_quantity));
    addCopyField('販売手数料率', draft.product.sales_fee_rate === null ? '' : `${draft.product.sales_fee_rate}%`);
    if (session && draft.product.images.length > 0) {
        const imageRow = document.createElement('div');
        imageRow.className = 'image-row';
        const imageLabel = document.createElement('span');
        imageLabel.textContent = '商品画像';
        imageRow.append(imageLabel);
        draftPanel.append(imageRow);
        for (const image of draft.product.images) {
            void fetch(`${session.baseUrl}${image.path}`, { headers: { Authorization: `Bearer ${session.accessToken}` } })
                .then((response) => {
                    if (!response.ok) throw new Error('画像を読み込めませんでした。');
                    return response.blob();
                })
                .then((blob) => {
                    const preview = document.createElement('img');
                    preview.src = URL.createObjectURL(blob);
                    preview.alt = `商品画像 ${image.position + 1}`;
                    preview.className = 'product-image';
                    imageRow.append(preview);
                    const saveImage = document.createElement('button');
                    saveImage.type = 'button';
                    saveImage.className = 'copy';
                    saveImage.textContent = '画像保存';
                    saveImage.addEventListener('click', () => {
                        const objectUrl = URL.createObjectURL(blob);
                        const anchor = document.createElement('a');
                        const extension = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : 'jpg';
                        const safeSku = draft.product.internal_sku.replace(/[^a-zA-Z0-9_-]/g, '_');
                        anchor.href = objectUrl;
                        anchor.download = `${safeSku}-${image.position + 1}.${extension}`;
                        anchor.click();
                        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
                        report('画像の保存を開始しました。');
                    });
                    imageRow.append(saveImage);
                })
                .catch(() => report('商品画像を表示できませんでした。'));
        }
    }
    if (prepared.reviewFields.length > 0) {
        const warning = document.createElement('p');
        warning.className = 'warning';
        const labels: Record<string, string> = { price: '価格', marketplace_category: '出品先カテゴリ', shipping_method: '配送方法', condition: '出品先向けの商品状態', description: '説明文' };
        warning.textContent = `要確認: ${prepared.reviewFields.map((field) => labels[field] ?? field).join('、')}`;
        draftPanel.append(warning);
    }
    const historyHeading = document.createElement('h3');
    historyHeading.textContent = '掲載履歴';
    draftPanel.append(historyHeading);
    if (draft.listing_history.length === 0) {
        const emptyHistory = document.createElement('p');
        emptyHistory.className = 'hint';
        emptyHistory.textContent = 'この出品先の掲載履歴はありません。';
        draftPanel.append(emptyHistory);
    } else {
        const history = document.createElement('ul');
        history.className = 'listing-history';
        for (const item of draft.listing_history) {
            const entry = document.createElement('li');
            const date = item.listed_at ? new Date(item.listed_at).toLocaleDateString('ja-JP') : '日付未記録';
            entry.textContent = `${date}・${item.price === null ? '価格未記録' : `¥${item.price.toLocaleString('ja-JP')}`}・${item.status === 'active' ? '掲載中' : item.status}`;
            if (item.url) {
                const link = document.createElement('a');
                link.href = item.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = '掲載ページ';
                entry.append('　', link);
            }
            history.append(entry);
        }
        draftPanel.append(history);
    }
    renderListingRecordForm(draft);
    const publishNote = document.createElement('p');
    publishNote.className = 'hint';
    publishNote.textContent = '一意に判定できない欄や、登録値とサイトの選択肢が一致しない項目は手動で入力してください。入力後は内容を確認し、出品確定はご自身で行ってください。';
    draftPanel.append(publishNote);
}

async function prefillMarketplaceListingForm(draft: Draft): Promise<void> {
    const [activeTab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    if (!activeTab?.id || !activeTab.url) {
        report(`${draft.platform.name}の出品フォームを開いてから実行してください。`);
        return;
    }

    let activeUrl: URL;
    try {
        activeUrl = new URL(activeTab.url);
    } catch {
        report('現在のタブを確認できません。公式出品フォームを開いてください。');
        return;
    }
    if (!adapterForPlatform(draft.platform.id).matchUrl(activeUrl)) {
        report(`${draft.platform.name}の公式ページを開いてから実行してください。`);
        return;
    }
    const marketplace = platforms.find((platform) => platform.id === draft.platform.id);
    if (!marketplace?.formPermissionOrigins?.length || !(await chrome.permissions.contains({ origins: marketplace.formPermissionOrigins }))) {
        report(`${draft.platform.name}の入力権限がありません。先に権限を明示的に許可してください。`);
        return;
    }

    let phase = '商品画像の取得';
    try {
        const images: MarketplaceImage[] = [];
        let imageBytes = 0;
        const imagesToTransfer = draft.product.images.slice(0, MAX_PRODUCT_IMAGE_COUNT);
        let omittedImages = Math.max(0, draft.product.images.length - imagesToTransfer.length);
        for (const [index, image] of imagesToTransfer.entries()) {
            if (!session) throw new Error('連携が切れています。');
            const imagePath = `/api/extension/v1/products/${draft.product.id}/images/${image.id}`;
            if (image.path !== imagePath) {
                throw new Error('画像URLを確認できません。');
            }
            const response = await fetch(`${session.baseUrl}${imagePath}`, { headers: { Authorization: `Bearer ${session.accessToken}`, Accept: 'image/*' } });
            if (!response.ok) throw new Error('画像を読み込めませんでした。');
            const blob = await response.blob();
            if (!blob.type.startsWith('image/')) throw new Error('画像形式を確認できません。');
            if (imageBytes + blob.size > MAX_PRODUCT_IMAGE_BYTES) {
                omittedImages += draft.product.images.length - index;
                break;
            }
            imageBytes += blob.size;
            const bytes = new Uint8Array(await blob.arrayBuffer());
            let binary = '';
            for (let offset = 0; offset < bytes.length; offset += 0x8000) binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + 0x8000, bytes.length)));
            const extension = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : blob.type === 'image/gif' ? 'gif' : 'jpg';
            images.push({ name: `furimadeck-${image.id}.${extension}`, type: blob.type, dataUrl: `data:${blob.type};base64,${btoa(binary)}` });
        }
        const values: MarketplacePrefillValues = {
            platformId: draft.platform.id,
            title: draft.product.title,
            description: draft.product.description,
            price: draft.product.price,
            category: draft.product.category,
            condition: draft.product.condition,
            shippingMethod: draft.product.shipping_method,
            shippingFee: draft.product.shipping_fee,
            quantity: draft.product.listing_quantity ?? draft.product.quantity_available,
            shippingPayer: draft.product.shipping_payer,
            senderRegion: draft.product.sender_region,
            dispatchDays: draft.product.dispatch_days,
            shippingSize: draft.product.shipping_size,
            shippingWeightGrams: draft.product.shipping_weight_grams,
            saleFormat: draft.product.sale_format,
            listingPeriodDays: draft.product.listing_period_days,
            returnPolicy: draft.product.return_policy,
            purchaseApplication: draft.product.purchase_application,
            janEan: draft.product.jan_ean ?? null,
            isbn: draft.product.isbn ?? null,
            manufacturerModelNumber: draft.product.manufacturer_model_number ?? null,
            serialNumber: draft.product.serial_number ?? null,
            attributes: draft.product.attributes ?? [],
            images,
            imageWarning: omittedImages > 0
                ? `商品画像は全${draft.product.images.length}枚中${images.length}枚だけ転送しました。未転送の画像は手動で追加してください。`
                : null,
        };
        phase = '出品フォームへの反映';
        const [execution] = await chrome.scripting.executeScript({
            target: { tabId: activeTab.id },
            func: prefillMarketplaceForm,
            args: [values],
        });
        const result = execution?.result as { filled?: string[]; missing?: string[]; ambiguous?: string[]; existing?: string[]; imageWarning?: string; page?: string } | undefined;
        if (result?.page !== 'listing') {
            report('出品フォームを判定できませんでした。入力は行っていません。');
            return;
        }
        const labels: Record<string, string> = { title: '商品名', description: '説明', price: '価格', category: 'カテゴリ', condition: '商品状態', shipping_method: '配送方法', shipping_fee: '送料', quantity: '数量', images: '画像' };
        const filled = (result.filled ?? []).map((field) => labels[field] ?? field);
        const unresolved = [...(result.missing ?? []), ...(result.ambiguous ?? [])].map((field) => labels[field] ?? field);
        const preserved = (result.existing ?? []).map((field) => labels[field] ?? field);
        report([
            filled.length ? `${filled.join('・')}を入力しました。` : '入力できる項目はありませんでした。',
            result.imageWarning ?? '',
            preserved.length ? `入力済みのため変更していません: ${preserved.join('・')}。` : '',
            unresolved.length ? `未入力（欄がない・複数候補）: ${unresolved.join('・')}。` : '',
            '入力内容を確認してください。出品確定は行っていません。',
        ].filter(Boolean).join(' '));
    } catch (error) {
        const reason = error instanceof Error ? error.message : '';
        report(reason ? `${phase}に失敗しました。${reason}` : `${phase}に失敗しました。ページを再読み込みして、もう一度お試しください。`);
    }
}

function renderListingRecordForm(draft: Draft): void {
    const form = document.createElement('form');
    form.className = 'record-form';
    const heading = document.createElement('h3');
    heading.textContent = '掲載URLを記録';
    const urlLabel = document.createElement('label');
    urlLabel.textContent = '出品先の商品URL';
    const urlInput = document.createElement('input');
    urlInput.type = 'url';
    urlInput.required = true;
    urlInput.placeholder = '実際に掲載されたページのURL';
    const priceLabel = document.createElement('label');
    priceLabel.textContent = '実際の出品価格';
    const priceInput = document.createElement('input');
    priceInput.type = 'number';
    priceInput.min = '0';
    priceInput.step = '1';
    priceInput.required = true;
    if (draft.product.price !== null) priceInput.value = String(draft.product.price);
    const confirmLabel = document.createElement('label');
    confirmLabel.className = 'confirm-label';
    const confirmInput = document.createElement('input');
    confirmInput.type = 'checkbox';
    confirmInput.required = true;
    confirmLabel.append(confirmInput, document.createTextNode('この商品が出品先に実際に掲載されたことを確認しました'));
    const submit = document.createElement('button');
    submit.type = 'submit';
    submit.className = 'primary';
    submit.textContent = '確認済みの掲載を記録';
    form.append(heading, urlLabel, urlInput, priceLabel, priceInput, confirmLabel, submit);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const productId = Number(draftPanel.dataset.productId);
        if (!productId) return;
        submit.disabled = true;
        report('確認済みの掲載履歴を保存中…');
        try {
            const result = await api<{ created: boolean }>(`/products/${productId}/listing-records`, {
                method: 'POST',
                body: JSON.stringify({
                    platform: draft.platform.id,
                    external_listing_url: urlInput.value.trim(),
                    listing_price: Number(priceInput.value),
                    confirmed_listed: confirmInput.checked,
                }),
            });
            await loadDraft(productId, draft.platform.id);
            report(result.created ? '掲載履歴を登録しました。' : 'この掲載URLはすでに登録済みです。');
        } catch (error) {
            report(error instanceof Error ? error.message : '掲載履歴を保存できませんでした。');
        } finally {
            submit.disabled = false;
        }
    });
    draftPanel.append(form);
}

async function disconnect(): Promise<void> {
    try { await api('/pairing/revoke', { method: 'POST' }); } catch { /* Local credentials are cleared even if offline. */ }
    await chrome.storage.local.remove(['connectedBaseUrl']);
    try {
        await chrome.scripting.unregisterContentScripts({ ids: ['furimadeck-product-link-bridge'] });
    } catch { /* The user may already have removed the extension permission. */ }
    await chrome.storage.session.remove(['furimadeckSession', 'pendingListingLaunch']);
    session = null;
    draftPanel.hidden = true;
    renderConnection();
    report('連携を解除しました。');
}

byId<HTMLButtonElement>('connect-button').addEventListener('click', async () => {
    if (!privacyConsentAccepted) return;
    report('FurimaDeckの承認画面を開きます…');
    try {
        const bridgeReady = await pair();
        report(bridgeReady ? '連携しました。' : '連携しましたが、商品画面との連携を登録できません。拡張のサイト権限を確認してください。');
    }
    catch (error) { report(error instanceof Error ? error.message : '連携に失敗しました。'); }
});
byId<HTMLButtonElement>('disconnect-button').addEventListener('click', () => void disconnect());
byId<HTMLButtonElement>('open-settings').addEventListener('click', () => void chrome.runtime.openOptionsPage());
byId<HTMLButtonElement>('detect-platform').addEventListener('click', async () => {
    if (!privacyConsentAccepted) return;
    const detected = byId<HTMLElement>('detected-platform');
    try {
        const result = await chrome.runtime.sendMessage({ type: 'FURIMADECK_INSPECT_ACTIVE_TAB' }) as { hostname?: string | null } | undefined;
        const platform = result?.hostname ? detectPlatform(result.hostname) : null;
        if (!platform) {
            detected.textContent = '表示中のフリマサイト：対応サイトを判定できませんでした';
            return;
        }
        detected.textContent = `表示中のフリマサイト：${platform.name}`;
    } catch {
        detected.textContent = '表示中のフリマサイト：このページは判定できません';
    }
});
document.querySelectorAll<HTMLButtonElement>('[data-export]').forEach((button) => {
    button.addEventListener('click', async () => {
        const kind = button.dataset.export;
        if (kind !== 'products' && kind !== 'listings' && kind !== 'sales') return;
        button.disabled = true;
        report('CSVを準備しています…');
        try {
            await downloadCsv(kind);
            report('CSVのダウンロードを開始しました。');
        } catch (error) {
            report(error instanceof Error ? error.message : 'CSVを出力できませんでした。');
        } finally {
            button.disabled = false;
        }
    });
});
byId<HTMLButtonElement>('open-product-import').addEventListener('click', () => {
    if (!session) {
        report('先にFurimaDeckアカウントを連携してください。');
        return;
    }
    try {
        const baseUrl = normalizeBaseUrl(session.baseUrl);
        void chrome.tabs.create({ url: `${baseUrl}/products/import` }).catch(() => {
            report('商品CSV取込画面を開けませんでした。接続先またはChromeの権限を確認してください。');
        });
    } catch {
        report('商品CSV取込画面を開けませんでした。接続先を確認してください。');
    }
});
templateForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = byId<HTMLInputElement>('template-name');
    const purpose = byId<HTMLSelectElement>('template-purpose');
    const body = byId<HTMLTextAreaElement>('template-body');
    try {
        const templateId = templateForm.dataset.templateId;
        await api(templateId ? `/templates/${templateId}` : '/templates', {
            method: templateId ? 'PATCH' : 'POST',
            body: JSON.stringify({ name: name.value.trim(), purpose: purpose.value, body: body.value }),
        });
        delete templateForm.dataset.templateId;
        templateForm.reset();
        byId<HTMLButtonElement>('template-save').textContent = '定型文を保存';
        await loadTemplates();
        report('定型文を保存しました。');
    } catch (error) {
        report(error instanceof Error ? error.message : '定型文を保存できませんでした。');
    }
});
void enableFeaturesAfterConsent().catch(() => report('設定を読み込めませんでした。'));
