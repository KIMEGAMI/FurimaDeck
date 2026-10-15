import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';
import { adapterForPlatform, adapterForUrl } from '../src/adapters.ts';
import { detectPlatform, platforms } from '../src/platforms.ts';
import { formatManualListingDraft } from '../src/draft.ts';
import { prefillMarketplaceForm } from '../src/mercari-prefill.ts';

const extensionPath = process.env.FURIMADECK_EXTENSION_PATH?.trim()
    ? path.resolve(process.env.FURIMADECK_EXTENSION_PATH)
    : path.resolve(import.meta.dirname, '../dist');
const userDataPath = path.resolve(import.meta.dirname, `.chrome-smoke-profile-${process.pid}-${Date.now()}`);
const testAccessToken = randomUUID().replaceAll('-', '');
await mkdir(userDataPath, { recursive: true });
let context;

const manifest = JSON.parse(await readFile(path.join(extensionPath, 'manifest.json'), 'utf8'));
const productBridgeScript = await readFile(path.join(extensionPath, 'product-bridge.js'), 'utf8');
assert.equal(manifest.manifest_version, 3);
assert.deepEqual(manifest.icons, { '128': 'icons/icon-128.png' });
assert.equal(await readFile(path.join(extensionPath, 'icons/icon-128.png')).then((icon) => icon.length > 0), true);
assert.deepEqual(manifest.permissions, ['storage', 'identity', 'clipboardWrite', 'activeTab', 'scripting']);
assert.deepEqual(manifest.optional_host_permissions, [
    'https://furimadeck.kimegami.jp/*',
    'http://127.0.0.1/*',
    'https://jp.mercari.com/*',
    'https://fril.jp/*',
    'https://paypayfleamarket-sec.yahoo.co.jp/*',
    'https://auctions.yahoo.co.jp/*',
]);
assert.equal(Object.hasOwn(manifest, 'host_permissions'), false);
assert.equal(Object.hasOwn(manifest, 'content_scripts'), false, 'Marketplace pages must not be inspected automatically.');
assert.match(productBridgeScript, /FURIMADECK_PRODUCT_LINK_CLICK/);

function contrastRatio(foreground, background) {
    const luminance = (color) => {
        const channels = color.match(/[\d.]+/g)?.slice(0, 3).map(Number);
        assert.equal(channels?.length, 3);
        const linear = channels.map((channel) => {
            const normalized = channel / 255;
            return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
    };
    const values = [luminance(foreground), luminance(background)].sort((left, right) => right - left);
    return (values[0] + 0.05) / (values[1] + 0.05);
}

assert.equal(detectPlatform('jp.mercari.com')?.id, 'mercari');
assert.equal(detectPlatform('fril.jp')?.id, 'rakuma');
assert.equal(detectPlatform('paypayfleamarket-sec.yahoo.co.jp')?.id, 'yahoo_flea_market');
assert.equal(detectPlatform('auctions.yahoo.co.jp')?.id, 'yahoo_auctions');
assert.equal(detectPlatform('mercari-shops.com')?.id, 'mercari_shops');
assert.equal(detectPlatform('notmercari.jp'), null);
assert.equal(detectPlatform('mercari.jp.attacker.example'), null);
assert.equal(platforms.find((platform) => platform.id === 'mercari')?.listingUrl, 'https://jp.mercari.com/sell/create');
for (const platformId of ['mercari', 'rakuma', 'yahoo_flea_market', 'yahoo_auctions', 'mercari_shops']) {
    const adapted = adapterForPlatform(platformId).prepareDraft({
        platformId,
        title: 'Saved marketplace title',
        description: 'Saved marketplace description',
        price: 1980,
        condition: '保存済みの状態',
        category: null,
        shippingMethod: null,
        shippingFee: null,
        reviewFields: ['marketplace_category', 'shipping_method'],
    });
    assert.equal(adapted.platform.id, platformId);
    assert.equal(adapted.title, 'Saved marketplace title');
    assert.deepEqual(adapted.reviewFields, ['marketplace_category', 'shipping_method']);
    assert.equal(adapted.operationLevel, 'manual_assist');
    assert.equal(adapted.authorizedPublish, false);
    assert.ok(adapted.capabilities.includes('manual_copy'));
    assert.ok(!adapted.capabilities.includes('authorized_publish'));
}
assert.equal(adapterForUrl(new URL('https://jp.mercari.com/item/example'))?.id, 'mercari');
assert.equal(adapterForUrl(new URL('http://jp.mercari.com/item/example')), null);
assert.equal(adapterForUrl(new URL('https://jp.mercari.com.attacker.example/')), null);
const clipboardDraft = formatManualListingDraft({
    platformName: 'メルカリ',
    internalSku: 'FD-001',
    title: 'Vintage coat',
    description: 'Good condition',
    price: 1980,
    condition: '目立った傷や汚れなし',
    category: 'コート',
    shippingMethod: 'らくらくメルカリ便',
    shippingFee: 210,
    salesFeeRate: '10.00',
    shippingPayer: 'seller',
    senderRegion: '広島県',
    dispatchDays: 2,
    shippingSize: '60サイズ',
    shippingWeightGrams: 800,
    saleFormat: 'fixed_price',
    listingPeriodDays: 7,
    returnPolicy: '返品不可',
    purchaseApplication: 'disabled',
    listingQuantity: 3,
    attributes: [{ label: 'ブランド', value: 'サンプル' }],
});
assert.match(clipboardDraft, /出品先: メルカリ/);
assert.match(clipboardDraft, /¥1,980/);
assert.match(clipboardDraft, /¥210/);
assert.match(clipboardDraft, /販売手数料率\n10\.00%/);
assert.match(clipboardDraft, /送料負担\n出品者負担/);
assert.match(clipboardDraft, /販売形式\n定額販売/);
assert.match(clipboardDraft, /購入申請\n購入申請なし/);
assert.match(clipboardDraft, /出品数量\n3/);
assert.match(clipboardDraft, /ブランド\nサンプル/);
assert.match(formatManualListingDraft({
    platformName: 'ラクマ', internalSku: 'FD-002', title: 'Item', description: null,
    price: null, condition: '未設定', category: null, shippingMethod: null,
    shippingFee: null, salesFeeRate: null,
}), /説明\n未設定/);

const prefillValues = (platformId, overrides = {}) => ({
    platformId,
    title: '試験タイトル',
    description: '試験説明',
    price: 1980,
    category: null,
    condition: null,
    shippingMethod: null,
    shippingFee: null,
    shippingPayer: null,
    senderRegion: null,
    dispatchDays: null,
    shippingSize: null,
    shippingWeightGrams: null,
    saleFormat: null,
    listingPeriodDays: null,
    returnPolicy: null,
    purchaseApplication: null,
    quantity: null,
    janEan: null,
    isbn: null,
    manufacturerModelNumber: null,
    serialNumber: null,
    attributes: [],
    images: [],
    imageWarning: null,
    ...overrides,
});

try {
    context = await chromium.launchPersistentContext(userDataPath, {
        channel: 'chrome',
        headless: true,
        acceptDownloads: true,
        ignoreDefaultArgs: ['--disable-extensions'],
        args: ['--enable-unsafe-extension-debugging'],
    });

    const fixturePage = await context.newPage();
    await fixturePage.route('https://jp.mercari.com/sell', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Mercari sell landing fixture</title>' }));
    await fixturePage.route('https://jp.mercari.com/sell/create', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Mercari fixture</title>' }));
    await fixturePage.route('https://jp.mercari.com/item/example', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Mercari item fixture</title>' }));
    await fixturePage.goto('https://jp.mercari.com/sell/create');
    await fixturePage.setContent([
        '<label for="name">商品名</label><input id="name" />',
        '<label for="description">商品の説明</label><textarea id="description"></textarea>',
        '<label for="price">販売価格</label><input id="price" inputmode="numeric" />',
    ].join(''));
    const filled = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari'));
    assert.deepEqual(filled, { filled: ['title', 'description', 'price'], missing: [], ambiguous: [], existing: [], page: 'listing' });
    assert.deepEqual(await fixturePage.locator('#name, #description, #price').evaluateAll((controls) => controls.map((control) => control.value)), ['試験タイトル', '試験説明', '1980']);

    await fixturePage.setContent([
        '<span id="title-label">商品名</span><div id="custom-title" role="textbox" aria-labelledby="title-label"></div>',
        '<label id="description-label">商品説明</label><div id="custom-description" contenteditable="true" aria-labelledby="description-label"></div>',
        '<label for="custom-price">価格</label><input id="custom-price" />',
    ].join(''));
    const customControls = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari'));
    assert.deepEqual(customControls, { filled: ['title', 'description', 'price'], missing: [], ambiguous: [], existing: [], page: 'listing' }, 'Uniquely labelled accessible editors must receive draft values.');
    assert.deepEqual(await fixturePage.locator('#custom-title, #custom-description, #custom-price').evaluateAll((controls) => controls.map((control) => control instanceof HTMLInputElement ? control.value : control.innerText)), ['試験タイトル', '試験説明', '1980']);

    await fixturePage.setContent([
        '<label>商品名<div id="custom-title" role="textbox">利用者の入力</div></label>',
        '<label>商品説明<div id="custom-description" contenteditable="true">既存の説明</div></label>',
        '<label for="custom-price">価格</label><input id="custom-price" />',
    ].join(''));
    const preservedCustom = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari'));
    assert.deepEqual(preservedCustom, { filled: ['price'], missing: [], ambiguous: [], existing: ['title', 'description'], page: 'listing' }, 'Existing accessible editor content must not be overwritten.');

    await fixturePage.setContent([
        '<span id="first-title-label">商品名</span><div role="textbox" aria-labelledby="first-title-label"></div>',
        '<span id="second-title-label">商品名</span><div role="textbox" aria-labelledby="second-title-label"></div>',
        '<label for="custom-price">価格</label><input id="custom-price" />',
    ].join(''));
    const ambiguousCustom = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { description: null }));
    assert.deepEqual(ambiguousCustom, { filled: ['price'], missing: [], ambiguous: ['title'], existing: [], page: 'listing' }, 'Ambiguous accessible editors must remain untouched.');
    assert.deepEqual(await fixturePage.locator('[role="textbox"]').evaluateAll((controls) => controls.map((control) => control.innerText)), ['', '']);

    await fixturePage.setContent('<main id="form-root"></main>');
    const delayedFormRenderMs = 100;
    const delayedPrefill = fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { title: '遅延フォーム', description: '遅延説明', price: 2500 }));
    await fixturePage.waitForTimeout(delayedFormRenderMs);
    await fixturePage.locator('#form-root').evaluate((root) => {
        root.innerHTML = '<label for="delayed-name">商品名</label><input id="delayed-name" /><label for="delayed-description">商品の説明</label><textarea id="delayed-description"></textarea><label for="delayed-price">販売価格</label><input id="delayed-price" />';
    });
    assert.deepEqual(await delayedPrefill, { filled: ['title', 'description', 'price'], missing: [], ambiguous: [], existing: [], page: 'listing' }, 'Late-rendered listing forms must still receive the draft.');
    assert.deepEqual(await fixturePage.locator('#delayed-name, #delayed-description, #delayed-price').evaluateAll((controls) => controls.map((control) => control.value)), ['遅延フォーム', '遅延説明', '2500']);

    await fixturePage.setContent([
        '<label for="name">商品名</label><input id="name" value="利用者の入力" />',
        '<label for="description">商品の説明</label><textarea id="description"></textarea>',
        '<label for="price">販売価格</label><input id="price" />',
    ].join(''));
    const preserved = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { title: '上書きしない', description: '説明', price: 500 }));
    assert.deepEqual(preserved, { filled: ['description', 'price'], missing: [], ambiguous: [], existing: ['title'], page: 'listing' });
    assert.equal(await fixturePage.locator('#name').inputValue(), '利用者の入力', 'Existing user input must never be overwritten.');

    await fixturePage.setContent([
        '<label for="name-one">商品名</label><input id="name-one" />',
        '<label for="name-two">商品名</label><input id="name-two" />',
        '<label for="price">販売価格</label><input id="price" />',
    ].join(''));
    const ambiguous = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { title: '入力しない', description: null, price: 0 }));
    assert.deepEqual(ambiguous, { filled: ['price'], missing: [], ambiguous: ['title'], existing: [], page: 'listing' });
    assert.deepEqual(await fixturePage.locator('#name-one, #name-two').evaluateAll((controls) => controls.map((control) => control.value)), ['', ''], 'Ambiguous fields must not be changed.');

    await fixturePage.goto('https://jp.mercari.com/item/example');
    const nonListing = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { title: '触れない', description: '触れない', price: 100 }));
    assert.deepEqual(nonListing, { filled: [], missing: [], ambiguous: [], existing: [], page: 'other' }, 'The experiment must not alter ordinary Mercari pages.');
    await fixturePage.goto('https://jp.mercari.com/sell');
    const landingPage = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues('mercari', { title: '触れない', description: '触れない', price: 100 }));
    assert.deepEqual(landingPage, { filled: [], missing: [], ambiguous: [], existing: [], page: 'other' }, 'The sell landing page is not the listing form and must not be modified.');

    const platformForms = [
        { id: 'rakuma', url: 'https://fril.jp/item/new', otherPath: '/item/newer', title: '商品名', price: '販売価格' },
        { id: 'yahoo_flea_market', url: 'https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop', otherPath: '/item/additional', title: '商品名', price: '価格' },
        { id: 'yahoo_auctions', url: 'https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0', otherPath: '/sell/jp/show/submit/extra', title: '商品名', price: '開始価格' },
    ];
    for (const platform of platformForms) {
        await fixturePage.route(platform.url, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Marketplace form fixture</title>' }));
        await fixturePage.goto(platform.url);
        await fixturePage.setContent([
            `<label for="title">${platform.title}</label><input id="title" />`,
            '<label for="description">商品説明</label><textarea id="description"></textarea>',
            `<label for="price">${platform.price}</label><input id="price" />`,
            '<label for="category">カテゴリ</label><select id="category"><option value="">選択してください</option><option value="tops">トップス</option></select>',
            '<label for="condition">商品状態</label><select id="condition"><option value="">選択してください</option><option value="used">目立った傷や汚れなし</option></select>',
            '<label for="shipping">配送方法</label><select id="shipping"><option value="">選択してください</option><option value="anon">匿名配送</option></select>',
            '<label for="fee">送料</label><input id="fee" />',
            '<label for="payer">送料負担</label><select id="payer"><option value="">選択してください</option><option value="seller">出品者負担</option></select>',
            '<label for="region">発送元地域</label><input id="region" />',
            '<label for="dispatch">発送までの日数</label><select id="dispatch"><option value="">選択してください</option><option value="2">2</option></select>',
            '<label for="size">配送サイズ</label><input id="size" />',
            '<label for="weight">配送重量（g）</label><input id="weight" />',
            '<label for="format">販売形式</label><select id="format"><option value="">選択してください</option><option value="fixed_price">定額販売</option></select>',
            '<label for="period">掲載期間（日）</label><input id="period" />',
            '<label for="return">返品方針</label><textarea id="return"></textarea>',
            '<label for="application">購入申請</label><select id="application"><option value="">選択してください</option><option value="disabled">購入申請なし</option></select>',
            '<label for="quantity">販売数量</label><input id="quantity" />',
            '<label for="jan">JANコード</label><input id="jan" />',
            '<label for="model">型番</label><input id="model" />',
            '<label for="brand">ブランド</label><input id="brand" />',
            '<label for="images">商品画像</label><input id="images" type="file" accept="image/*" multiple />',
        ].join(''));
        const values = prefillValues(platform.id, {
            title: '入力支援テスト', description: '登録済み説明', price: 1980, category: 'トップス',
            condition: '目立った傷や汚れなし', shippingMethod: '匿名配送', shippingFee: 210, quantity: 1,
            shippingPayer: '出品者負担', senderRegion: '広島県', dispatchDays: 2, shippingSize: '60サイズ',
            shippingWeightGrams: 800, saleFormat: 'fixed_price', listingPeriodDays: 30,
            returnPolicy: '返品不可', purchaseApplication: 'disabled',
            janEan: '4900000000000', manufacturerModelNumber: 'MODEL-01',
            attributes: [{ label: 'ブランド', value: 'サンプルブランド' }],
            images: [{ name: 'test.png', type: 'image/png', dataUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/fuoAAAAASUVORK5CYII=' }],
        });
        const result = await fixturePage.evaluate(prefillMarketplaceForm, values);
        assert.equal(result.page, 'listing', `${platform.id} listing route should be recognized.`);
        assert.deepEqual(result.filled, ['title', 'description', 'price', 'category', 'condition', 'shipping_method', 'shipping_fee', 'shipping_payer', 'sender_region', 'dispatch_days', 'shipping_size', 'shipping_weight_grams', 'sale_format', 'listing_period_days', 'return_policy', 'purchase_application', 'quantity', 'jan_ean', 'manufacturer_model_number', 'attribute:ブランド', 'images']);
        assert.deepEqual(await fixturePage.locator('#title, #description, #price, #category, #condition, #shipping, #fee, #payer, #region, #dispatch, #size, #weight, #format, #period, #return, #application, #quantity, #jan, #model, #brand').evaluateAll((controls) => controls.map((control) => control.value)), [
            '入力支援テスト', '登録済み説明', '1980', 'tops', 'used', 'anon', '210', 'seller', '広島県', '2', '60サイズ', '800', 'fixed_price', '30', '返品不可', 'disabled', '1', '4900000000000', 'MODEL-01', 'サンプルブランド',
        ]);
        assert.equal(await fixturePage.locator('#fee').inputValue(), '210');
        assert.equal(await fixturePage.locator('#brand').inputValue(), 'サンプルブランド');
        assert.equal(await fixturePage.locator('#fee').inputValue(), '210');
        assert.equal(await fixturePage.locator('#quantity').inputValue(), '1');
        assert.equal(await fixturePage.locator('#images').evaluate((input) => input.files?.[0]?.name), 'test.png');
        const imageOmission = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues(platform.id, {
            title: '', description: null, price: null, images: [],
            imageWarning: '商品画像は全3枚中2枚だけ転送しました。未転送の画像は手動で追加してください。',
        }));
        assert.match(imageOmission.imageWarning ?? '', /全3枚中2枚だけ転送/);
        assert.equal(await fixturePage.locator('form').count(), 0, 'Prefill fixtures must never submit a form.');

        const unrelatedUrl = new URL(platform.url);
        unrelatedUrl.pathname = platform.otherPath;
        unrelatedUrl.search = '';
        await fixturePage.route(unrelatedUrl.toString(), (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Non-listing route fixture</title>' }));
        await fixturePage.goto(unrelatedUrl.toString());
        await fixturePage.setContent('<label for="unrelated-title">商品名</label><input id="unrelated-title" />');
        const unrelatedResult = await fixturePage.evaluate(prefillMarketplaceForm, prefillValues(platform.id, { title: '誤入力してはいけない' }));
        assert.deepEqual(unrelatedResult, { filled: [], missing: [], ambiguous: [], existing: [], page: 'other' }, `${platform.id} must not touch a nearby but non-listing route.`);
        assert.equal(await fixturePage.locator('#unrelated-title').inputValue(), '', `${platform.id} must not fill fields outside the exact listing route.`);
    }
    await fixturePage.close();

    const browser = context.browser();
    if (!browser) {
        throw new Error('Playwright did not expose the launched Chrome browser.');
    }

    const browserSession = await browser.newBrowserCDPSession();
    let extensionId;
    try {
        ({ id: extensionId } = await browserSession.send('Extensions.loadUnpacked', { path: extensionPath }));
    } finally {
        await browserSession.detach();
    }

    assert.match(extensionId ?? '', /^[a-p]{32}$/);
    let worker = context.serviceWorkers()[0] ?? await context.waitForEvent('serviceworker', { timeout: 10_000 }).catch(() => null);
    if (!worker) {
        throw new Error('Chrome did not expose the extension service worker.');
    }
    assert.equal(new URL(worker.url()).host, extensionId);

    const popup = await context.newPage();
    const pageErrors = [];
    popup.on('pageerror', (error) => pageErrors.push(error.message));
    await popup.goto(`chrome-extension://${extensionId}/popup.html`);
    await popup.waitForFunction(() => document.querySelector('#privacy-consent')?.hidden === false, null, { timeout: 5_000 });
    assert.equal(await popup.title(), 'FurimaDeck 出品アシスト');
    assert.equal(await popup.locator('#privacy-consent').isVisible(), true);
    assert.match(await popup.locator('#privacy-consent').innerText(), /選択した出品フォームのラベル・属性・選択肢・現在値を端末内で一時的に確認します/);
    assert.match(await popup.locator('#privacy-consent').innerText(), /フォーム情報やフリマのURL・ホスト名はFurimaDeckへ送信・保存せず/);
    assert.match(await popup.locator('#privacy-consent').innerText(), /サイト側の仕様によっては入力時に下書き保存や画像アップロード等の処理が行われる可能性があります/);
    assert.match(await popup.locator('#privacy-consent').innerText(), /サイト側の処理までは制御しません/);
    assert.equal(await popup.locator('#feature-content').isHidden(), true);
    assert.equal(await popup.locator('#privacy-consent-accept').isDisabled(), true);
    assert.equal(await popup.locator('#detected-platform').textContent(), '表示中のフリマサイト：未判定', 'Page hostname must not be inspected before consent.');
    await popup.locator('#privacy-consent-check').check();
    await popup.locator('#privacy-consent-accept').click();
    await popup.waitForFunction(() => document.querySelector('#feature-content')?.hidden === false);
    assert.equal(await popup.locator('#connect-panel').isVisible(), true);
    assert.equal(await popup.locator('#connected-panel').isHidden(), true);
    assert.equal(await popup.locator('#templates-panel').count(), 1);
    assert.equal(await popup.locator('#open-product-import').count(), 1);
    assert.equal(await popup.locator('#product-list').count(), 0, 'The extension popup must not contain a product catalog.');
    assert.equal(await popup.locator('#search').count(), 0, 'Products are chosen from the FurimaDeck product screen, not searched in the popup.');
    await popup.locator('#detect-platform').click();
    await popup.waitForFunction(() => document.querySelector('#detected-platform')?.textContent?.includes('対応サイトを判定できませんでした'));
    await popup.locator('#server-url').fill('https://example.com');
    await popup.locator('#connect-button').click();
    await popup.waitForFunction(() => document.querySelector('#status')?.textContent?.includes('接続先URLの確認で失敗しました。'));
    assert.match(await popup.locator('#status').textContent(), /登録済みのFurimaDeck URL/);
    await popup.locator('#server-url').fill('http://127.0.0.1:8001');
    assert.deepEqual(pageErrors, []);

    const pairingPopup = await context.newPage();
    const pairingId = '00000000-0000-4000-8000-000000000007';
    const pairingCode = 'f'.repeat(64);
    const pairedAccessToken = randomUUID().replaceAll('-', '');
    await pairingPopup.addInitScript(({ apiOrigin, pairingId, pairingCode, pairedAccessToken }) => {
        const json = (payload, status = 200) => new Response(JSON.stringify(payload), {
            status,
            headers: { 'Content-Type': 'application/json' },
        });
        window.__pairingCalls = [];
        window.__identityScopes = ['products:read', 'drafts:read', 'listings:write', 'templates:read', 'templates:write', 'exports:read', 'activity:read'];
        window.__permissionOrigins = [];
        window.__grantedOptionalOrigins = [];
        window.__registeredContentScripts = [];
        window.__injectedBridgeScripts = [];
        window.__openedMarketplaceUrls = [];
        window.__injectedMercariPrefills = [];
        window.__injectedMarketplacePrefills = [];
        Object.defineProperty(chrome.tabs, 'create', {
            configurable: true,
            value: async ({ url }) => {
                window.__openedMarketplaceUrls.push(url);
                return { id: window.__openedMarketplaceUrls.length };
            },
        });
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async (text) => { window.__copiedText = text; } },
        });
        window.__activeTabUrl = 'http://127.0.0.1:8001/products/42/edit';
        Object.defineProperty(chrome.permissions, 'request', {
            configurable: true,
            value: async ({ origins }) => {
                window.__permissionOrigins = origins;
                window.__grantedOptionalOrigins.push(...origins);
                return true;
            },
        });
        Object.defineProperty(chrome.permissions, 'contains', {
            configurable: true,
            value: async ({ origins }) => origins.every((origin) => window.__grantedOptionalOrigins.includes(origin)),
        });
        Object.defineProperty(chrome.scripting, 'registerContentScripts', {
            configurable: true,
            value: async (scripts) => { window.__registeredContentScripts = scripts; },
        });
        Object.defineProperty(chrome.scripting, 'unregisterContentScripts', {
            configurable: true,
            value: async () => undefined,
        });
        Object.defineProperty(chrome.tabs, 'query', {
            configurable: true,
            value: async () => {
                const { testActiveTabUrl } = await chrome.storage.local.get(['testActiveTabUrl']);
                return [{ id: 19, url: testActiveTabUrl ?? window.__activeTabUrl }];
            },
        });
        Object.defineProperty(chrome.scripting, 'executeScript', {
            configurable: true,
            value: async (options) => {
                if (options.func) {
                    window.__injectedMercariPrefills.push({ target: options.target, args: options.args });
                    window.__injectedMarketplacePrefills.push({ target: options.target, args: options.args });
                    return [{ result: { filled: ['title', 'description', 'price'], missing: [], ambiguous: [], existing: [], page: 'listing' } }];
                }
                window.__injectedBridgeScripts.push(options);
                return [];
            },
        });
        Object.defineProperty(chrome.identity, 'launchWebAuthFlow', {
            configurable: true,
            value: async ({ url, interactive }) => {
                window.__approvalLaunch = { url, interactive };
                return `${chrome.identity.getRedirectURL('pair')}#pairing_id=${pairingId}&code=${pairingCode}`;
            },
        });
        window.fetch = async (input, init = {}) => {
            const url = new URL(typeof input === 'string' ? input : input.url);
            const body = init.body ? JSON.parse(String(init.body)) : null;
            window.__pairingCalls.push({ pathname: url.pathname, method: init.method ?? 'GET', body });
            if (url.origin !== apiOrigin) return json({ message: 'Unexpected test origin' }, 404);
            if (url.pathname.endsWith('/pairing/start')) {
                window.__pairingStartBody = body;
                return json({ pairing_id: pairingId, approval_url: `${apiOrigin}/chrome-extension/pair/${pairingId}` }, 201);
            }
            if (url.pathname.endsWith('/pairing/complete')) {
                window.__pairingCompleteBody = body;
                return json({ access_token: pairedAccessToken, user: { name: 'Pairing smoke user' } });
            }
            if (url.pathname.endsWith('/me')) {
                const { testIdentityScopes } = await chrome.storage.local.get(['testIdentityScopes']);
                return json({ user: { name: 'Pairing smoke user' }, scopes: testIdentityScopes ?? window.__identityScopes });
            }
            if (url.pathname.endsWith('/templates') || url.pathname.endsWith('/products')) {
                return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
            }
            if (url.pathname.endsWith('/products/42/images/9')) {
                const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="#256b77"/></svg>';
                const image = new Image();
                image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = 32;
                canvas.height = 32;
                canvas.getContext('2d')?.drawImage(image, 0, 0);
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                if (!(blob instanceof Blob)) return json({ message: 'Image fixture failed' }, 500);
                return new Response(blob, { status: 200, headers: { 'Content-Type': 'image/png' } });
            }
            if (/\/products\/[0-9]+\/draft$/.test(url.pathname)) {
                const productId = Number(url.pathname.match(/\/products\/([0-9]+)\/draft$/)?.[1]);
                const platformId = url.searchParams.get('platform');
                const platformNames = {
                    mercari: 'メルカリ',
                    rakuma: 'ラクマ',
                    yahoo_flea_market: 'Yahoo!フリマ',
                    yahoo_auctions: 'Yahoo!オークション',
                    mercari_shops: 'メルカリShops',
                };
                window.__openedDraft = { productId, platformId };
                return json({
                    product: {
                        id: productId, internal_sku: 'FD-BRIDGE-42', product_name: 'Bridge test item',
                        title: 'Bridge test title', description: 'Bridge test description', price: 1800,
                        condition: '保存済みの状態', category: '保存済みカテゴリ', shipping_method: '配送方法',
                        shipping_fee: 210, shipping_payer: null, sender_region: null, dispatch_days: null,
                        shipping_size: null, shipping_weight_grams: null, sale_format: null, listing_period_days: null,
                        return_policy: null, purchase_application: null, listing_quantity: null,
                        sales_fee_rate: '5.00', quantity_available: 1,
                        jan_ean: '4900000000000', manufacturer_model_number: 'MODEL-01',
                        attributes: [{ label: 'ブランド', value: 'サンプルブランド' }],
                        images: [{ id: 9, position: 0, path: '/api/extension/v1/products/42/images/9' }],
                    },
                    platform: { id: platformId, name: platformNames[platformId], operation_level: 'manual_assist' },
                    review_fields: [], authorized_publish: false, listing_history: [],
                });
            }
            return json({ message: 'Unexpected test API endpoint' }, 404);
        };
    }, { apiOrigin: 'http://127.0.0.1:8001', pairingId, pairingCode, pairedAccessToken });
    await pairingPopup.goto(`chrome-extension://${extensionId}/popup.html`);
    await pairingPopup.locator('#connect-button').click();
    await pairingPopup.waitForFunction(() => document.querySelector('#connection-state')?.textContent === '連携済み');
    assert.equal(await pairingPopup.locator('#account-name').textContent(), 'Pairing smoke user');
    assert.deepEqual(await pairingPopup.evaluate(() => window.__permissionOrigins), ['http://127.0.0.1:8001/*']);
    assert.match(await pairingPopup.locator('#marketplace-permissions').textContent(), /メルカリ：出品ページを開くだけ/);
    await pairingPopup.locator('#grant-marketplace-mercari').click();
    assert.deepEqual(await pairingPopup.evaluate(() => window.__permissionOrigins), ['https://jp.mercari.com/*']);
    assert.match(await pairingPopup.locator('#marketplace-permissions').textContent(), /メルカリ：入力権限あり/);
    assert.deepEqual(await pairingPopup.evaluate(() => window.__registeredContentScripts), [{
        id: 'furimadeck-product-link-bridge',
        matches: ['http://127.0.0.1:8001/*'],
        js: ['product-bridge.js'],
        runAt: 'document_idle',
        persistAcrossSessions: true,
    }]);
    assert.deepEqual(await pairingPopup.evaluate(() => window.__injectedBridgeScripts), [{
        target: { tabId: 19 },
        files: ['product-bridge.js'],
    }]);
    assert.equal(await pairingPopup.evaluate(() => window.__approvalLaunch?.interactive), true);
    assert.match(await pairingPopup.evaluate(() => window.__approvalLaunch?.url ?? ''), /\/chrome-extension\/pair\//);
    const pairingStartBody = await pairingPopup.evaluate(() => window.__pairingStartBody);
    assert.match(pairingStartBody.code_challenge, /^[A-Za-z0-9_-]{43}$/);
    assert.match(pairingStartBody.redirect_uri, new RegExp(`^https://${extensionId}\\.chromiumapp\\.org/pair$`));
    const pairingCompleteBody = await pairingPopup.evaluate(() => window.__pairingCompleteBody);
    assert.equal(pairingCompleteBody.pairing_id, pairingId);
    assert.equal(pairingCompleteBody.code, pairingCode);
    assert.match(pairingCompleteBody.verifier, /^[a-f0-9]{64}$/);
    assert.equal(await pairingPopup.evaluate(() => window.__pairingCalls.some((call) => call.pathname.endsWith('/products') && call.method === 'GET')), false, 'Pairing must not load a product catalog into the popup.');
    assert.equal(await worker.evaluate(async (expectedToken) => {
        const { furimadeckSession } = await chrome.storage.session.get(['furimadeckSession']);
        return furimadeckSession?.baseUrl === 'http://127.0.0.1:8001' && furimadeckSession?.accessToken === expectedToken;
    }, pairedAccessToken), true, 'A successful pairing must persist its access token only in session storage.');
    assert.equal(await pairingPopup.evaluate(async () => (await chrome.storage.local.get(['connectedBaseUrl'])).connectedBaseUrl), 'http://127.0.0.1:8001');
    const selectedPlatforms = [
        { id: 'mercari', name: 'メルカリ', url: 'https://jp.mercari.com/sell/create' },
        { id: 'rakuma', name: 'ラクマ', url: 'https://fril.jp/item/new' },
        { id: 'yahoo_flea_market', name: 'Yahoo!フリマ', url: 'https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop' },
        { id: 'yahoo_auctions', name: 'Yahoo!オークション', url: 'https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0' },
    ];
    for (const [index, platform] of selectedPlatforms.entries()) {
        await worker.evaluate(async ({ platformId }) => chrome.storage.session.set({
            pendingListingLaunch: {
                productId: 42,
                platformId,
                expiresAt: Date.now() + 600_000,
            },
        }), { platformId: platform.id });
        await pairingPopup.evaluate(async (activeUrl) => chrome.storage.local.set({ testActiveTabUrl: activeUrl }), platform.url);
        await pairingPopup.reload();
        await pairingPopup.waitForFunction((name) => document.querySelector('#draft-panel h2')?.textContent === `${name}向けドラフト`, platform.name, { timeout: 5_000 }).catch(async () => {
            process.stdout.write(`Product-link handoff diagnostics: ${JSON.stringify(await pairingPopup.evaluate(() => ({
                status: document.querySelector('#status')?.textContent,
                apiCalls: window.__pairingCalls,
                openedDraft: window.__openedDraft,
            })))}\n`);
            throw new Error(`The selected ${platform.name} product draft was not restored in the extension popup.`);
        });
        assert.match(await pairingPopup.locator('#status').textContent(), new RegExp(`${platform.name}のドラフトを読み込みました`));
        assert.match(await pairingPopup.locator('#draft-panel .hint').last().textContent(), /一意に判定できない欄や、登録値とサイトの選択肢が一致しない項目は手動で入力してください/);
        assert.deepEqual(await pairingPopup.evaluate(() => window.__openedDraft), { productId: 42, platformId: platform.id });
        assert.equal(await worker.evaluate(async () => (await chrome.storage.session.get(['pendingListingLaunch'])).pendingListingLaunch), undefined);
        if (index === 0) {
            assert.match(await pairingPopup.locator('#draft-panel .warning').textContent(), /ラクマは公式サポート/);
        }
        const permissionButton = pairingPopup.locator(`#grant-marketplace-${platform.id}`);
        if (await permissionButton.count()) await permissionButton.click();
        const previousInjectionCount = await pairingPopup.evaluate(() => window.__injectedMarketplacePrefills.length);
        await pairingPopup.locator('#draft-panel button').filter({ hasText: '入力欄に反映（試作）' }).click();
        await pairingPopup.waitForFunction((count) => window.__injectedMarketplacePrefills.length > count || document.querySelector('#status')?.textContent?.includes('失敗'), previousInjectionCount);
        const injectedValues = await pairingPopup.evaluate(() => window.__injectedMarketplacePrefills.at(-1)?.args?.[0]);
        assert.ok(injectedValues, `The explicit fill click should inject on ${platform.id}. Popup status: ${await pairingPopup.locator('#status').textContent()}`);
        assert.equal(injectedValues.platformId, platform.id);
        assert.equal(injectedValues.title, 'Bridge test title');
        assert.equal(injectedValues.description, 'Bridge test description');
        assert.equal(injectedValues.price, 1800);
        assert.equal(injectedValues.category, '保存済みカテゴリ');
        assert.equal(injectedValues.condition, '保存済みの状態');
        assert.equal(injectedValues.janEan, '4900000000000');
        assert.equal(injectedValues.manufacturerModelNumber, 'MODEL-01');
        assert.deepEqual(injectedValues.attributes, [{ label: 'ブランド', value: 'サンプルブランド' }]);
        assert.equal(injectedValues.images.length, 1);
        assert.match(await pairingPopup.locator('#status').textContent(), /入力内容を確認してください。出品確定は行っていません/);

        await pairingPopup.locator('#draft-panel .copy-all').click();
        assert.match(await pairingPopup.evaluate(() => window.__copiedText), new RegExp(`出品先: ${platform.name}`));
        assert.match(await pairingPopup.evaluate(() => window.__copiedText), /商品ID\nFD-BRIDGE-42/);
        assert.match(await pairingPopup.evaluate(() => window.__copiedText), /¥1,800/);
        assert.deepEqual(await pairingPopup.evaluate(() => window.__pairingCalls.filter((call) => call.method !== 'GET')), [], 'Preparing or copying a marketplace draft must never publish or write to a marketplace.');

        if (index === 0) {
            const titleField = pairingPopup.locator('.draft-field').filter({ has: pairingPopup.getByText('タイトル', { exact: true }) });
            await titleField.locator('button.copy').click();
            assert.equal(await pairingPopup.evaluate(() => window.__copiedText), 'Bridge test title', 'A user must be able to copy one field without copying the whole draft.');

            const imageSave = pairingPopup.locator('.image-row button.copy').first();
            await imageSave.waitFor({ state: 'visible', timeoutMs: 5_000 });
            assert.equal(await pairingPopup.locator('.image-row img').count(), 1, 'The selected product image should be previewed before saving.');
            const imageDownloadEvent = pairingPopup.waitForEvent('download');
            await imageSave.click();
            const imageDownload = await imageDownloadEvent;
            assert.equal(imageDownload.suggestedFilename(), 'FD-BRIDGE-42-1.png', 'The selected product image should be saved with its product ID and sequence.');
        }

        const expectedListingUrl = platform.url;
        await pairingPopup.locator('.marketplace-action').filter({ hasText: platform.name }).click();
        assert.equal(await pairingPopup.evaluate(() => window.__openedMarketplaceUrls.at(-1)), expectedListingUrl);

        if (index < selectedPlatforms.length - 1) {
            assert.equal(await pairingPopup.locator('#draft-panel .record-form input[type="checkbox"]').isChecked(), false);
        }
    }
    await pairingPopup.evaluate(async () => chrome.storage.local.set({
        testIdentityScopes: ['products:read', 'listings:write', 'templates:read', 'templates:write', 'exports:read', 'activity:read'],
    }));
    await pairingPopup.reload();
    await pairingPopup.waitForFunction(() => document.querySelector('#scope-warning')?.hidden === false, { timeout: 5000 }).catch(async () => {
        process.stdout.write(`Missing-scope diagnostics: ${JSON.stringify(await pairingPopup.evaluate(async () => ({
            status: document.querySelector('#status')?.textContent,
            warningHidden: document.querySelector('#scope-warning')?.hidden,
            connection: document.querySelector('#connection-state')?.textContent,
            scopes: (await chrome.storage.local.get(['testIdentityScopes'])).testIdentityScopes,
            hasSession: Boolean((await chrome.storage.session.get(['furimadeckSession'])).furimadeckSession),
        })))}\\n`);
        throw new Error('Missing API scopes were not detected.');
    });
    assert.match(await pairingPopup.locator('#status').textContent(), /拡張の権限を更新してください/);
    await pairingPopup.close();

    const bridgePage = await context.newPage();
    await bridgePage.goto('about:blank');
    await bridgePage.evaluate(() => {
        window.__bridgeMessages = [];
        Object.defineProperty(window, 'chrome', {
            configurable: true,
            value: { runtime: { sendMessage: async (message) => { window.__bridgeMessages.push(message); } } },
        });
    });
    await bridgePage.setContent([
        '<a id="mercari" data-marketplace-shortcut="mercari" data-product-id="42">Mercari</a>',
        '<a id="rakuma" data-marketplace-shortcut="rakuma" data-product-id="42">Rakuma</a>',
        '<a id="yahoo-flea" data-marketplace-shortcut="yahoo_flea_market" data-product-id="42"><span>Yahoo Flea Market</span></a>',
        '<a id="yahoo-auctions" data-marketplace-shortcut="yahoo_auctions" data-product-id="42">Yahoo Auctions</a>',
        '<a id="mercari-shops" data-marketplace-shortcut="mercari_shops" data-product-id="42">Mercari Shops</a>',
        '<a id="invalid" data-marketplace-shortcut="not-a-site" data-product-id="43">Unknown</a>',
    ].join(''));
    await bridgePage.addScriptTag({ path: path.join(extensionPath, 'product-bridge.js') });
    await bridgePage.addScriptTag({ path: path.join(extensionPath, 'product-bridge.js') });
    await bridgePage.evaluate(() => {
        window.__mercariLinkWasIntercepted = false;
        document.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('#mercari')) {
                window.__mercariLinkWasIntercepted = event.defaultPrevented;
            }
        }, { capture: true });
    });
    for (const platformId of ['mercari', 'rakuma', 'yahoo-flea', 'yahoo-auctions', 'mercari-shops']) {
        await bridgePage.locator(`#${platformId}`).click();
    }
    await bridgePage.locator('#invalid').click();
    assert.equal(await bridgePage.evaluate(() => window.__mercariLinkWasIntercepted), true, 'Mercari link navigation must be handled by the extension before opening/filling the listing tab.');
    assert.deepEqual(await bridgePage.evaluate(() => window.__bridgeMessages), [{
        type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'mercari',
    }, {
        type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'rakuma',
    }, {
        type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'yahoo_flea_market',
    }, {
        type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'yahoo_auctions',
    }]);
    await bridgePage.close();

    await popup.addInitScript((apiOrigin) => {
        const response = (payload, status = 200, headers = {}) => new Response(
            typeof payload === 'string' ? payload : JSON.stringify(payload),
            { status, headers: { 'Content-Type': 'application/json', ...headers } },
        );
        window.__apiCalls = [];
        window.__apiOffline = false;
        window.__failTabOpen = false;
        window.__openedMarketplaceUrls = [];
        Object.defineProperty(chrome.tabs, 'create', {
            configurable: true,
            value: async ({ url }) => {
                if (window.__failTabOpen) throw new Error('Tab creation denied');
                window.__openedMarketplaceUrls.push(url);
                return { id: window.__openedMarketplaceUrls.length };
            },
        });
        window.fetch = async (input, init = {}) => {
            const url = new URL(typeof input === 'string' ? input : input.url);
            const method = init.method ?? 'GET';
            const hasAuthorization = new Headers(init.headers).has('Authorization');
            window.__apiCalls.push({ path: `${url.pathname}${url.search}`, method, hasAuthorization });
            if (window.__apiOffline) throw new TypeError('Failed to fetch');
            if (url.origin !== apiOrigin) return response({ message: 'Unexpected test request' }, 404);
            if (/\/exports\/(products|listings|sales)\.csv$/.test(url.pathname)) {
                return response('internal_sku,product_name\nFD-SMOKE-07,テスト商品\n', 200, { 'Content-Type': 'text/csv; charset=utf-8' });
            }
            if (url.pathname.endsWith('/me')) {
                return response({ user: { name: 'Chrome smoke user' }, scopes: ['products:read', 'drafts:read', 'listings:write', 'templates:read', 'templates:write', 'exports:read', 'activity:read'] });
            }
            if (url.pathname.endsWith('/templates')) {
                if (method === 'POST') return response({ data: { id: 2 } }, 201);
                return response({ data: [{ id: 1, name: '発送案内', purpose: 'shipping', body: '発送予定です。' }] });
            }
            if (url.pathname.endsWith('/products/7/listing-records') && method === 'POST') {
                return response({ created: true, listing: { id: 11 } }, 201);
            }
            if (url.pathname.endsWith('/products/7/images/9')) {
                const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="360" viewBox="0 0 360 360"><rect width="360" height="360" fill="#f1f5f9"/><path d="M120 72 165 52h30l45 20 48 60-38 30-20-25v155H130V137l-20 25-38-30z" fill="#256b77" stroke="#124550" stroke-width="8" stroke-linejoin="round"/><path d="m165 52 15 28 15-28M180 80v215" fill="none" stroke="#d9eef0" stroke-width="7"/><circle cx="194" cy="120" r="5" fill="#f8fafc"/><circle cx="194" cy="160" r="5" fill="#f8fafc"/><circle cx="194" cy="200" r="5" fill="#f8fafc"/></svg>';
                const image = new Image();
                image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
                await image.decode();
                const canvas = document.createElement('canvas');
                canvas.width = 360;
                canvas.height = 360;
                canvas.getContext('2d')?.drawImage(image, 0, 0);
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                if (!(blob instanceof Blob)) return response({ message: 'Image fixture failed' }, 500);
                return new Response(blob, { status: 200, headers: { 'Content-Type': 'image/png' } });
            }
            if (/\/products\/[0-9]+\/draft$/.test(url.pathname)) {
                const platformNames = {
                    mercari: 'メルカリ',
                    rakuma: 'ラクマ',
                    yahoo_flea_market: 'Yahoo!フリマ',
                    yahoo_auctions: 'Yahoo!オークション',
                    mercari_shops: 'メルカリShops',
                };
                const platform = url.searchParams.get('platform');
                return response({
                    product: {
                        id: Number(url.pathname.match(/\/products\/([0-9]+)\/draft$/)?.[1]),
                        internal_sku: 'FD-SMOKE-07',
                        product_name: 'テスト商品',
                        title: `保存済み${platformNames[platform]}タイトル`,
                        description: '権利を持つ商品説明',
                        price: 1980,
                        condition: '保存済みの商品状態',
                        category: '保存済みの出品カテゴリ',
                        shipping_method: '保存済みの配送方法',
                        shipping_fee: 210,
                        shipping_payer: null,
                        sender_region: null,
                        dispatch_days: null,
                        shipping_size: null,
                        shipping_weight_grams: null,
                        sale_format: null,
                        listing_period_days: null,
                        return_policy: null,
                        purchase_application: null,
                        listing_quantity: null,
                        sales_fee_rate: '10.00',
                        quantity_available: 1,
                        images: [{ id: 9, position: 0, path: '/api/extension/v1/products/7/images/9' }],
                    },
                    platform: { id: platform, name: platformNames[platform], operation_level: 'manual_assist' },
                    review_fields: ['marketplace_category', 'shipping_method'],
                    authorized_publish: false,
                    listing_history: [],
                });
            }
            if (url.pathname.endsWith('/products')) {
                const query = url.searchParams.get('query')?.trim() ?? '';
                const page = url.searchParams.get('page') ?? '1';
                if (!query && page === '2') {
                    return response({
                        data: [{ id: 9, internal_sku: 'FD-PAGE-09', product_name: '次ページ商品', category: null, condition: 'used_good', quantity_available: 1 }],
                        meta: { current_page: 2, last_page: 2, total: 2 },
                    });
                }
                if (query === 'テスト商品') {
                    return response({
                        data: [{ id: 8, internal_sku: 'FD-SEARCH-08', product_name: 'テスト商品', category: '衣類', condition: 'used_good', quantity_available: 1 }],
                        meta: { current_page: 1, last_page: 1, total: 1 },
                    });
                }
                if (query && query !== 'FD-SMOKE-07') {
                    return response({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
                }
                return response({
                    data: [{ id: 7, internal_sku: 'FD-SMOKE-07', product_name: 'テスト商品', category: '衣類', condition: 'used_good', quantity_available: 1 }],
                    meta: { current_page: 1, last_page: query ? 1 : 2, total: query ? 1 : 2 },
                });
            }
            return response({ message: 'Unexpected test API endpoint' }, 404);
        };
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async (text) => { window.__copiedText = text; } },
        });
    }, 'http://127.0.0.1:8001');
    await worker.evaluate(async (accessToken) => {
        await chrome.storage.session.set({ furimadeckSession: {
            baseUrl: 'http://127.0.0.1:8001',
            accessToken,
            userName: 'Chrome smoke user',
        } });
    }, testAccessToken);
    await popup.reload();
    await popup.waitForFunction(() => document.querySelector('#connection-state')?.textContent === '連携済み');
    const exportsPanel = popup.locator('#exports-panel');
    if (!(await exportsPanel.evaluate((element) => element.open))) {
        await popup.locator('#exports-panel summary').click();
    }
    await popup.locator('#open-product-import').click();
    assert.equal(await popup.evaluate(() => window.__openedMarketplaceUrls.includes('http://127.0.0.1:8001/products/import')), true, 'The import button should open the local FurimaDeck import page.');
    assert.equal(new URL(await popup.evaluate(() => window.__openedMarketplaceUrls.find((url) => url.includes('/products/import')))).search, '', 'The import URL must not contain the extension token.');
    await popup.evaluate(() => { window.__failTabOpen = true; });
    await popup.locator('#open-product-import').click();
    await popup.waitForFunction(() => document.querySelector('#status')?.textContent?.includes('商品CSV取込画面を開けませんでした。'));
    assert.match(await popup.locator('#status').textContent(), /Chromeの権限を確認してください/);
    await popup.evaluate(() => { window.__failTabOpen = false; });
    assert.equal(await popup.locator('#product-list').count(), 0, 'No product catalog should be rendered in the extension popup.');
    assert.equal(await popup.locator('#search').count(), 0, 'The popup must not include product search.');
    assert.equal(await popup.evaluate(() => window.__apiCalls.some((call) => new URL(call.path, location.href).pathname === '/api/extension/v1/products')), false, 'The popup must not retrieve the full product catalog.');

    await popup.locator('#templates-panel summary').click();
    await popup.locator('#template-list .template-row button.copy').click();
    assert.equal(await popup.evaluate(() => window.__copiedText), '発送予定です。');
    await popup.locator('#template-name').fill('E2E案内');
    await popup.locator('#template-body').fill('これはテスト用テンプレートです。');
    await popup.locator('#template-save').click();
    await popup.waitForFunction(() => document.querySelector('#status')?.textContent === '定型文を保存しました。');
    assert.equal(await popup.evaluate(() => window.__apiCalls.some((call) => call.method === 'POST' && call.path.endsWith('/templates'))), true);

    if (!(await exportsPanel.evaluate((element) => element.open))) {
        await popup.locator('#exports-panel summary').click();
    }
    for (const kind of ['products', 'listings', 'sales']) {
        const exportDownloadEvent = popup.waitForEvent('download');
        await popup.locator(`[data-export="${kind}"]`).click();
        const exportDownload = await exportDownloadEvent;
        assert.equal(exportDownload.suggestedFilename(), `furimadeck-${kind}.csv`);
    }
    const apiCallsHaveAuthorization = await popup.evaluate(() => window.__apiCalls.every((call) => call.hasAuthorization));
    assert.equal(apiCallsHaveAuthorization, true, 'Every API request must carry authorization without exposing its value to the test.');

    await popup.evaluate(() => { window.__apiOffline = true; });
    await popup.locator('[data-export="products"]').click();
    await popup.waitForFunction(() => document.querySelector('#status')?.textContent?.includes('Failed to fetch'));
    await popup.evaluate(() => { window.__apiOffline = false; });

    const workerSession = await context.newCDPSession(popup);
    try {
        let resolveRegistrations;
        let resolveVersions;
        const registrationEvent = new Promise((resolve) => { resolveRegistrations = resolve; });
        const versionEvent = new Promise((resolve) => { resolveVersions = resolve; });
        workerSession.on('ServiceWorker.workerRegistrationUpdated', (event) => resolveRegistrations(event.registrations));
        workerSession.on('ServiceWorker.workerVersionUpdated', (event) => resolveVersions(event.versions));
        await workerSession.send('ServiceWorker.enable');
        const [registrations, versions] = await Promise.all([registrationEvent, versionEvent]);
        const registration = registrations.find((entry) => entry.scopeURL === `chrome-extension://${extensionId}/`);
        const version = versions.find((entry) => entry.scriptURL === worker.url());
        assert.ok(registration, 'Chrome must register the extension service worker.');
        assert.ok(version, 'Chrome must report the running extension service worker version.');
        await workerSession.send('ServiceWorker.stopWorker', { versionId: version.versionId });
        const workerRestartEvent = context.waitForEvent('serviceworker', { timeout: 5_000 }).catch(() => null);
        await workerSession.send('ServiceWorker.startWorker', { scopeURL: registration.scopeURL });
        const restartDeadline = Date.now() + 5_000;
        let restarted = false;
        while (Date.now() < restartDeadline) {
            const targets = await workerSession.send('Target.getTargets');
            restarted = targets.targetInfos.some((target) => target.type === 'service_worker' && target.url === worker.url());
            if (restarted) break;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        assert.equal(restarted, true, 'Chrome must be able to start the service worker again after stopping it.');
        const restartedWorker = await workerRestartEvent;
        worker = restartedWorker?.url() === version.scriptURL
            ? restartedWorker
            : context.serviceWorkers().find((candidate) => candidate.url() === version.scriptURL);
        assert.ok(worker, 'Playwright must expose the restarted extension service worker before it is used again.');
    } finally {
        await workerSession.detach();
    }
    assert.deepEqual(pageErrors, []);
    await worker.evaluate(async () => chrome.storage.session.remove(['furimadeckSession']));

    const options = await context.newPage({ viewport: { width: 1280, height: 800 } });
    const optionsErrors = [];
    options.on('pageerror', (error) => optionsErrors.push(error.message));
    await worker.evaluate(async () => chrome.storage.local.remove(['furimadeckPrivacyConsent']));
    await options.goto(`chrome-extension://${extensionId}/options.html`);
    assert.equal(await options.locator('#privacy-consent').isVisible(), true);
    assert.equal(await options.locator('#settings-content').isHidden(), true);
    await options.locator('#privacy-consent-check').check();
    await options.locator('#privacy-consent-accept').click();
    await options.waitForFunction(() => document.querySelector('#settings-content')?.hidden === false);
    await options.waitForFunction(() => document.querySelector('#connection-state')?.textContent === '未連携');
    assert.equal(await options.locator('#platform-list li').count(), 5);
    assert.equal(await options.locator('#permission-list li').count(), 3);
    assert.equal(await options.locator('#activity-list li').count(), 1);
    assert.equal(await options.locator('#copy-diagnostics').isVisible(), true);
    assert.equal(await options.locator('#disconnect').isHidden(), true);
    const storeAssetsPath = path.resolve(import.meta.dirname, '../store-assets');
    const promoTileHtml = await readFile(path.join(storeAssetsPath, 'promo-tile.html'), 'utf8');
    const storeListing = await readFile(path.resolve(import.meta.dirname, '../STORE_LISTING.md'), 'utf8');
    assert.match(promoTileHtml, /出品フォームの空欄へ入力補助/);
    assert.match(promoTileHtml, /フォーム送信はしません/);
    assert.doesNotMatch(promoTileHtml, /自動入力・自動出品は行いません/);
    assert.match(storeListing, /設定画面/);
    assert.match(storeListing, /提出禁止の旧素材:[\s\S]*アップロードしないでください/);
    assert.match(storeListing, /公式出品フォームの識別できる空欄へ入力します/);
    assert.match(storeListing, /フォーム送信や出品確定は行わず/);
    assert.match(storeListing, /データの取扱い:.*アカウント表示名.*利用者がCSV出力を選んだ場合/);
    assert.match(storeListing, /選択した商品IDと出品先をブラウザー内に最長10分保持します/);
    assert.match(storeListing, /フォーム情報や閲覧先URL・ホスト名はFurimaDeckへ送信しません/);
    assert.match(storeListing, /2026-08-01から.*収集データの明確な開示を要求/);
    assert.doesNotMatch(storeListing, /公式画面で手動で出品できます/);
    await mkdir(storeAssetsPath, { recursive: true });
    const productPreview = await context.newPage();
    await productPreview.setViewportSize({ width: 1280, height: 800 });
    await productPreview.goto(pathToFileURL(path.join(storeAssetsPath, '03-product-edit-shortcuts.html')).href);
    assert.match(await productPreview.locator('aside').innerText(), /商品画面から|公式の出品画面/);
    const productScreenshot = await productPreview.screenshot({ path: path.join(storeAssetsPath, '03-product-edit-shortcuts-1280x800.png') });
    assert.equal(productScreenshot.readUInt32BE(16), 1280, 'Chrome Web Store product screenshots must be 1280 pixels wide.');
    assert.equal(productScreenshot.readUInt32BE(20), 800, 'Chrome Web Store product screenshots must be 800 pixels tall.');
    await productPreview.close();
    await options.setViewportSize({ width: 1280, height: 800 });
    const listingScreenshot = await options.screenshot({ path: path.join(storeAssetsPath, '01-connection-and-permissions-1280x800.png') });
    assert.equal(listingScreenshot.readUInt32BE(16), 1280, 'Chrome Web Store screenshots must be 1280 pixels wide.');
    assert.equal(listingScreenshot.readUInt32BE(20), 800, 'Chrome Web Store screenshots must be 800 pixels tall.');
    const promoTile = await context.newPage();
    await promoTile.setViewportSize({ width: 440, height: 280 });
    await promoTile.goto(pathToFileURL(path.join(storeAssetsPath, 'promo-tile.html')).href);
    const promoScreenshot = await promoTile.screenshot({ path: path.join(storeAssetsPath, '02-promo-tile-440x280.png') });
    assert.equal(promoScreenshot.readUInt32BE(16), 440, 'Chrome Web Store promo tiles must be 440 pixels wide.');
    assert.equal(promoScreenshot.readUInt32BE(20), 280, 'Chrome Web Store promo tiles must be 280 pixels tall.');
    await promoTile.close();
    await options.locator('#theme').selectOption('light');
    await options.waitForFunction(() => document.documentElement.dataset.theme === 'light');
    const lightPalette = await options.locator('body').evaluate((element) => ({
        foreground: getComputedStyle(element).color,
        background: getComputedStyle(element).backgroundColor,
    }));
    assert.ok(contrastRatio(lightPalette.foreground, lightPalette.background) >= 4.5, 'Light theme body text must meet WCAG AA contrast.');
    await popup.reload();
    await popup.waitForFunction(() => document.documentElement.dataset.theme === 'light');
    assert.deepEqual(optionsErrors, []);
    assert.deepEqual(pageErrors, []);

    await worker.evaluate(async (accessToken) => chrome.storage.session.set({ furimadeckSession: {
        baseUrl: 'http://127.0.0.1:8001',
        accessToken,
        userName: 'Chrome smoke user',
    } }), testAccessToken);
    const diagnostics = await context.newPage();
    diagnostics.on('pageerror', (error) => optionsErrors.push(error.message));
    await diagnostics.addInitScript(() => {
        window.fetch = async () => new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async (text) => { window.__copiedText = text; } },
        });
    });
    await diagnostics.goto(`chrome-extension://${extensionId}/options.html`);
    await diagnostics.waitForFunction(() => document.querySelector('#connection-state')?.textContent === '連携済み');
    await diagnostics.locator('#copy-diagnostics').click();
    const diagnosticsText = await diagnostics.evaluate(() => window.__copiedText);
    assert.match(diagnosticsText, /connection=paired/);
    assert.equal(diagnosticsText.includes(testAccessToken), false);
    assert.doesNotMatch(diagnosticsText, /Chrome smoke user|127\.0\.0\.1:8001/);
    await worker.evaluate(async () => chrome.storage.session.remove(['furimadeckSession']));
    await diagnostics.close();

    const backgroundLocalStorage = {
        furimadeckPrivacyConsent: '2026-10-15-marketplace-site-processing-disclosure',
        connectedBaseUrl: 'http://127.0.0.1:8001',
    };
    const backgroundSessionStorage = {
        furimadeckSession: { baseUrl: 'http://127.0.0.1:8001', accessToken: randomUUID(), userName: 'Background test user' },
    };
    const backgroundFetches = [];
    const backgroundTabCalls = [];
    const backgroundScriptCalls = [];
    let backgroundLargeImageScenario = false;
    let backgroundMessageListener;
    let backgroundUpdateListener;
    let backgroundTab = { id: 81, status: 'loading', url: 'about:blank' };
    const storageArea = (store) => ({
        get: async (keys) => Object.fromEntries((Array.isArray(keys) ? keys : [keys]).filter((key) => Object.hasOwn(store, key)).map((key) => [key, store[key]])),
        set: async (values) => Object.assign(store, values),
        remove: async (keys) => { for (const key of Array.isArray(keys) ? keys : [keys]) delete store[key]; },
    });
    const previousChrome = globalThis.chrome;
    const previousFetch = globalThis.fetch;
    const integrationPage = await context.newPage();
    for (const platform of platforms.filter(({ id }) => ['mercari', 'rakuma', 'yahoo_flea_market', 'yahoo_auctions'].includes(id))) {
        await integrationPage.route(platform.listingUrl, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><title>${platform.name} integration fixture</title>` }));
    }
    const integrationFormHtml = [
        '<form id="listing-form">',
        '<label for="integration-title">商品名</label><input id="integration-title" />',
        '<label for="integration-description">商品の説明</label><textarea id="integration-description"></textarea>',
        '<label for="integration-price">価格</label><input id="integration-price" />',
        '<label for="integration-images">商品画像</label><input id="integration-images" type="file" accept="image/*" />',
        '<button type="submit">出品する</button>',
        '</form>',
        '<script>window.__submitted = false; document.querySelector("form").addEventListener("submit", (event) => { event.preventDefault(); window.__submitted = true; });</script>',
    ].join('');
    globalThis.chrome = {
        runtime: {
            onInstalled: { addListener: () => undefined },
            onMessage: { addListener: (listener) => { backgroundMessageListener = listener; } },
        },
        tabs: {
            onUpdated: { addListener: (listener) => { backgroundUpdateListener = listener; } },
            create: async (properties) => { backgroundTabCalls.push({ type: 'create', properties }); backgroundTab = { id: 81, status: 'loading', url: properties.url }; return { id: 81 }; },
            update: async (tabId, properties) => {
                backgroundTabCalls.push({ type: 'update', tabId, properties });
                await integrationPage.goto(properties.url);
                await integrationPage.setContent(integrationFormHtml);
                backgroundTab = { id: tabId, status: 'complete', url: properties.url };
                backgroundUpdateListener?.(tabId, { status: 'complete' }, backgroundTab);
            },
            get: async () => backgroundTab,
        },
        storage: { local: storageArea(backgroundLocalStorage), session: storageArea(backgroundSessionStorage) },
        permissions: { contains: async ({ origins }) => origins.every((origin) => [
            'https://jp.mercari.com/*', 'https://fril.jp/*', 'https://paypayfleamarket-sec.yahoo.co.jp/*', 'https://auctions.yahoo.co.jp/*',
        ].includes(origin)) },
        scripting: {
            executeScript: async (options) => {
                backgroundScriptCalls.push(options);
                return [{ result: await integrationPage.evaluate(options.func, options.args[0]) }];
            },
        },
    };
    globalThis.fetch = async (input, init) => {
        const url = new URL(typeof input === 'string' || input instanceof URL ? input.toString() : input.url);
        backgroundFetches.push({ url: `${url.origin}${url.pathname}${url.search}`, authorization: new Headers(init?.headers).get('Authorization') });
        if (url.pathname.endsWith('/images/9')) {
            const bytes = backgroundLargeImageScenario ? new Uint8Array(4 * 1024 * 1024) : new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10]);
            return new Response(bytes, { status: 200, headers: { 'Content-Type': 'image/png' } });
        }
        if (url.pathname.endsWith('/images/10')) {
            return new Response(new Uint8Array(2 * 1024 * 1024), { status: 200, headers: { 'Content-Type': 'image/png' } });
        }
        return new Response(JSON.stringify({ product: {
            title: 'Background title', description: 'Background description', price: 1980,
            condition: '保存済みの状態', category: '保存済みカテゴリ', shipping_method: '配送方法', shipping_fee: 210,
            shipping_payer: '出品者負担', sender_region: '広島県', dispatch_days: 2, shipping_size: '60サイズ',
            shipping_weight_grams: 800, sale_format: 'fixed_price', listing_period_days: 30,
            return_policy: '返品不可', purchase_application: 'disabled', listing_quantity: 2, quantity_available: 5,
            jan_ean: '4900000000000', isbn: null, manufacturer_model_number: 'MODEL-01', serial_number: null,
            attributes: [{ label: 'ブランド', value: 'サンプルブランド' }],
            images: backgroundLargeImageScenario
                ? [
                    { id: 9, position: 0, path: '/api/extension/v1/products/42/images/9' },
                    { id: 10, position: 1, path: '/api/extension/v1/products/42/images/10' },
                ]
                : [{ id: 9, position: 0, path: '/api/extension/v1/products/42/images/9' }],
        } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };
    try {
        await import(pathToFileURL(path.join(extensionPath, 'service-worker.js')).href);
        const backgroundReply = new Promise((resolve) => {
            backgroundMessageListener({ type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'mercari' }, {
                tab: { id: 19, url: 'http://127.0.0.1:8001/products/42/edit' },
            }, resolve);
        });
        assert.deepEqual(await backgroundReply, { saved: true, opened: true });
        assert.deepEqual(backgroundFetches.map(({ url }) => url), [
            'http://127.0.0.1:8001/api/extension/v1/products/42/draft?platform=mercari',
            'http://127.0.0.1:8001/api/extension/v1/products/42/images/9',
        ]);
        assert.equal(backgroundFetches[0].authorization, `Bearer ${backgroundSessionStorage.furimadeckSession.accessToken}`);
        assert.deepEqual(backgroundTabCalls, [
            { type: 'create', properties: { url: 'about:blank', active: true } },
            { type: 'update', tabId: 81, properties: { url: 'https://jp.mercari.com/sell/create' } },
        ]);
        assert.deepEqual(backgroundScriptCalls.map(({ target, args }) => ({ target, args })), [{
            target: { tabId: 81 },
            args: [{ platformId: 'mercari', title: 'Background title', description: 'Background description', price: 1980, category: '保存済みカテゴリ', condition: '保存済みの状態', shippingMethod: '配送方法', shippingFee: 210, shippingPayer: '出品者負担', senderRegion: '広島県', dispatchDays: 2, shippingSize: '60サイズ', shippingWeightGrams: 800, saleFormat: 'fixed_price', listingPeriodDays: 30, returnPolicy: '返品不可', purchaseApplication: 'disabled', quantity: 2, janEan: '4900000000000', isbn: null, manufacturerModelNumber: 'MODEL-01', serialNumber: null, attributes: [{ label: 'ブランド', value: 'サンプルブランド' }], images: [{ name: 'furimadeck-9.png', type: 'image/png', dataUrl: 'data:image/png;base64,iVBORw0KGgo=' }], imageWarning: null }],
        }]);
        assert.deepEqual(await integrationPage.locator('#integration-title, #integration-description, #integration-price').evaluateAll((controls) => controls.map((control) => control.value)), ['Background title', 'Background description', '1980'], 'The product-link flow must input API draft values into the synthetic marketplace form.');
        assert.equal(await integrationPage.locator('#integration-images').evaluate((input) => input.files?.[0]?.name), 'furimadeck-9.png', 'The product-link flow must transfer the registered image into the synthetic marketplace form.');
        assert.equal(await integrationPage.evaluate(() => window.__submitted), false, 'The product-link flow must not submit the marketplace form.');
        assert.match(backgroundSessionStorage.marketplacePrefillStatus, /入力補助を実行しました/);
        assert.equal(backgroundFetches.some(({ url }) => url.includes('listing-records') || url.includes('submit')), false, 'The product-click flow must not submit a listing or update FurimaDeck listing records.');

        for (const platform of platforms.filter(({ id }) => ['rakuma', 'yahoo_flea_market', 'yahoo_auctions'].includes(id))) {
            const fetchCountBeforeLaunch = backgroundFetches.length;
            const reply = new Promise((resolve) => {
                backgroundMessageListener({ type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: platform.id }, {
                    tab: { id: 19, url: 'http://127.0.0.1:8001/products/42/edit' },
                }, resolve);
            });
            assert.deepEqual(await reply, { saved: true, opened: true });
            assert.equal(backgroundTabCalls.at(-1).properties.url, platform.listingUrl, `${platform.id} must open its official listing URL.`);
            assert.equal(backgroundFetches.slice(fetchCountBeforeLaunch).some(({ url }) => url.endsWith(`/draft?platform=${platform.id}`)), true, `${platform.id} must fetch its matching draft.`);
            assert.equal(backgroundScriptCalls.at(-1).args[0].platformId, platform.id);
            assert.deepEqual(await integrationPage.locator('#integration-title, #integration-description, #integration-price').evaluateAll((controls) => controls.map((control) => control.value)), ['Background title', 'Background description', '1980'], `${platform.id} must receive the draft values in its synthetic listing form.`);
            assert.equal(await integrationPage.locator('#integration-images').evaluate((input) => input.files?.[0]?.name), 'furimadeck-9.png', `${platform.id} must receive the registered image.`);
            assert.equal(await integrationPage.evaluate(() => window.__submitted), false, `${platform.id} must not submit the listing form.`);
            assert.match(backgroundSessionStorage.marketplacePrefillStatus, /入力補助を実行しました/);
        }

        backgroundLargeImageScenario = true;
        const largeImageReply = new Promise((resolve) => {
            backgroundMessageListener({ type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'yahoo_flea_market' }, {
                tab: { id: 19, url: 'http://127.0.0.1:8001/products/42/edit' },
            }, resolve);
        });
        assert.deepEqual(await largeImageReply, { saved: true, opened: true });
        const imageTransferArgs = backgroundScriptCalls.at(-1).args[0];
        assert.equal(imageTransferArgs.images.length, 1, 'Images beyond the transfer byte ceiling must not be passed into the marketplace page.');
        assert.match(imageTransferArgs.imageWarning ?? '', /全2枚中1枚だけ転送/);
        backgroundLargeImageScenario = false;

        const fetchCountBeforeConsentRevocation = backgroundFetches.length;
        delete backgroundLocalStorage.furimadeckPrivacyConsent;
        const noConsentReply = new Promise((resolve) => {
            backgroundMessageListener({ type: 'FURIMADECK_PRODUCT_LINK_CLICK', productId: 42, platformId: 'yahoo_flea_market' }, {
                tab: { id: 19, url: 'http://127.0.0.1:8001/products/42/edit' },
            }, resolve);
        });
        assert.deepEqual(await noConsentReply, { saved: false, opened: true }, 'Revoking consent must preserve ordinary marketplace navigation without saving the selected product.');
        assert.deepEqual(backgroundTabCalls.at(-1), {
            type: 'create',
            properties: { url: 'https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop' },
        });
        assert.equal(backgroundFetches.length, fetchCountBeforeConsentRevocation, 'No FurimaDeck API or image request may occur after consent is revoked.');
        assert.equal(backgroundSessionStorage.pendingListingLaunch, undefined, 'The selected product must not be retained after consent is revoked.');
    } finally {
        globalThis.fetch = previousFetch;
        if (previousChrome === undefined) delete globalThis.chrome;
        else globalThis.chrome = previousChrome;
    }

    process.stdout.write('Chrome extension E2E passed: MV3 load/restart, consent gates, product-link-to-synthetic-form flow with mocked API/tab handling, multi-marketplace form-mapping/image-transfer fixtures, delayed form fields, ambiguity/non-listing/no-overwrite safeguards, no marketplace submission, pairing/session handling, templates, CSV, offline recovery, storage clear, safe diagnostics, and theme contrast. Logged-in live marketplace compatibility remains a manual verification.\n');
} finally {
    await context?.close();
    await rm(userDataPath, { recursive: true, force: true });
}
