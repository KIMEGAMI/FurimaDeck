import { expect, test } from '@playwright/test';

async function productIdByName(page, productName) {
  await page.goto(`/products?keyword=${encodeURIComponent(productName)}`);
  await expect(page.getByText(productName).first()).toBeVisible();
  const productCard = page.locator('article').filter({ hasText: productName });
  const productHref = await productCard.locator('a[href*="/products/"]').first().getAttribute('href');
  const productId = productHref?.match(/\/products\/(\d+)(?:\/|$)/)?.[1];
  expect(productId).toBeTruthy();

  return productId;
}

async function openProductEditByName(page, productName) {
  const productId = await productIdByName(page, productName);
  await page.goto(`/products/${productId}`);
  await expect(page).toHaveURL(new RegExp(`/products/${productId}$`));
  await expect(page.getByRole('heading', { name: '商品詳細・編集' })).toBeVisible();
  await expect(page.locator('input[name="product_name"]')).toBeVisible();
}

async function setPurchaseInfo(page, productName, purchaseUnitCost) {
  const productId = await productIdByName(page, productName);
  await page.goto(`/products/${productId}/edit`);
  await page.locator('input[name="purchase_unit_cost"]').fill(String(purchaseUnitCost));
  await page.getByRole('button', { name: '更新する' }).click();
  await expect(page.getByText('商品を更新しました。')).toBeVisible();
}

async function createListingForProduct(page, productName, title) {
  await page.goto('/listings/create');
  const productOption = page.locator('select[name="product_id"] option', { hasText: productName });
  await expect(productOption).not.toHaveCount(0);
  await page.locator('select[name="product_id"]').selectOption(await productOption.last().getAttribute('value'));
  await page.locator('select[name="marketplace_id"]').selectOption({ index: 1 });
  await page.locator('input[name="listing_title"]').fill(title);
  await page.locator('input[name="listing_price"]').fill('5000');
  await page.locator('input[name="expected_fee_rate"]').fill('10');
  await page.locator('input[name="shipping_fee"]').fill('750');
  await page.locator('select[name="status"]').selectOption('active');
  await page.getByRole('button', { name: '出品情報を登録' }).click();
  await expect(page).toHaveURL(/\/listings\/\d+\/edit/);
}

async function openSaleCreateForProduct(page, productName) {
  await page.goto(`/products?keyword=${encodeURIComponent(productName)}`);
  const productCard = page.locator('article').filter({ hasText: productName });
  const saleLink = productCard.getByRole('link', { name: '売上を記録' });
  await expect(saleLink).toBeVisible();
  await saleLink.click();
  await expect(page).toHaveURL(/\/furimadeck-sales\/create\?product_id=\d+/);
  await expect(page.locator('input[name="product_id"]')).toHaveValue(/^\d+$/);
}

test('login page renders the authentication controls', async ({ page }) => {
  await page.goto('/login');
  await expect(page).toHaveTitle(/FurimaDeck/i);
  await expect(page.locator('input#email')).toBeVisible();
  await expect(page.locator('input#password')).toBeVisible();
  await expect(page.getByRole('button', { name: 'ログイン' })).toBeVisible();
});

test('public pricing page shows the approved monthly price and trial period', async ({ page }) => {
  const response = await page.goto('/pricing');

  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).toContainText('月額980円（税込）');
  await expect(page.locator('body')).toContainText('7日間無料お試し後');
  await expect(page.locator('body')).toContainText('商品画像・出品情報の登録、販売記録、在庫更新が可能');
  await expect(page.locator('body')).toContainText('CSV登録・出力、売上分析、会計CSVは利用不可');
  await expect(page.locator('body')).toContainText('売上・利益・ジャンル・クロス分析と販売改善');
  await expect(page.locator('body')).not.toContainText('販売管理');
  await expect(page.locator('body')).not.toContainText('480円');
});

test('public FAQ describes the configured stagnant inventory threshold and manual search', async ({ page }) => {
  const response = await page.goto('/faq');

  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).toContainText(/\d+日以上売れていない商品を確認できますか？/);
  await expect(page.locator('body')).toContainText('商品一覧で未販売の日数を指定して検索できます。');
  await expect(page.locator('body')).toContainText('未出品の商品は対象外です。');
  await expect(page.locator('body')).not.toContainText('10日以上売れていない商品');
});

test('commercial disclosure does not advertise a legacy category registration limit', async ({ page }) => {
  const response = await page.goto('/commercial-transactions');

  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).not.toContainText('カテゴリ登録数の制限');
  await expect(page.locator('body')).not.toContainText('カテゴリ数の制限なし');
});

test('public privacy policy does not expose the excluded Chrome extension', async ({ page }) => {
  const response = await page.goto('/privacy');

  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).not.toContainText('Chrome拡張');
  await expect(page.locator('body')).not.toContainText('Limited Use');
  await expect(page.locator('body')).toContainText('Resendを利用します');
  await expect(page.locator('body')).toContainText('主な処理拠点が米国');
  await expect(page.getByRole('link', { name: 'Resendのプライバシーポリシー' })).toHaveAttribute('href', 'https://resend.com/legal/privacy-policy');
  await expect(page.getByRole('link', { name: 'データ処理契約（DPA）' })).toHaveAttribute('href', 'https://resend.com/legal/dpa');
  await expect(page.getByRole('link', { name: '委託先一覧' })).toHaveAttribute('href', 'https://resend.com/legal/subprocessors');
});

test('contact form shows an error instead of claiming delivery when mail is unavailable', async ({ page }) => {
  await page.goto('/contact');
  await page.getByLabel('お名前').fill('E2E Test User');
  await page.getByLabel('メールアドレス').fill('e2e@example.test');
  await page.getByLabel('件名').fill('送信失敗表示の確認');
  await page.getByLabel('お問い合わせ内容').fill('隔離ブラウザーでメール未送信時の表示を確認します。');
  await page.getByRole('button', { name: '送信する' }).click();

  await expect(page.getByText('お問い合わせの送信設定が未完了です。時間をおいて再度お試しください。')).toBeVisible();
  await expect(page.getByText('お問い合わせを受け付けました。内容を確認し、必要に応じて返信します。')).toHaveCount(0);
});

test('CSV management routes require authentication', async ({ page }) => {
  for (const path of ['/products/import', '/furimadeck-export/restore/spec']) {
    const response = await page.goto(path);
    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('input#email')).toBeVisible();
  }
});

test('authenticated user can register a product', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);
  const desktopDashboardDetails = page.locator('#furimadeck-dashboard details.dashboard-mobile-collapsible');
  await desktopDashboardDetails.first().waitFor();
  expect(await desktopDashboardDetails.count()).toBeGreaterThan(5);
  expect(await desktopDashboardDetails.evaluateAll((sections) => sections.every((section) => section.open))).toBe(true);
  expect(await desktopDashboardDetails.locator('summary').evaluateAll((summaries) => summaries.every((summary) => getComputedStyle(summary).display === 'none'))).toBe(true);

  await page.goto('/products/create');
  const sku = `E2E-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2Eテスト商品');
  await expect(page.locator('input[name="purchase_unit_cost"]')).toHaveCount(1);
  await page.locator('input[name="purchase_date"]').fill('2026-11-01');
  await page.locator('input[name="purchase_unit_cost"]').fill('890');
  await page.locator('input[name="purchase_shipping_cost"]').fill('75');
  await page.locator('input[name="other_purchase_expense"]').fill('25');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByText(sku)).toBeVisible();
  await expect(page.getByText('E2Eテスト商品').first()).toBeVisible();
  const productCard = page.locator('article').filter({ hasText: sku });
  await productCard.getByRole('link', { name: '商品詳細・編集', exact: true }).click();
  await expect(page).toHaveURL(/\/products\/\d+$/);
  await expect(page.locator('input[name="purchase_date"]')).toHaveValue('2026-11-01');
  await expect(page.locator('input[name="purchase_unit_cost"]')).toHaveValue('890');
  await expect(page.locator('input[name="purchase_shipping_cost"]')).toHaveValue('75');
  await expect(page.locator('input[name="other_purchase_expense"]')).toHaveValue('25');
});
test('product selection opens editable details and allows deleting an unsold product', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const productName = `E2E削除確認-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(`E2E-DELETE-${Date.now()}`);
  await page.locator('input[name="product_name"]').fill(productName);
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  const productCard = page.locator('article').filter({ hasText: productName });
  await expect(productCard.getByRole('link', { name: '商品詳細・編集', exact: true })).toBeVisible();
  await productCard.locator('h3 a').click();
  await expect(page).toHaveURL(/\/products\/\d+$/);
  await expect(page.getByRole('heading', { name: '商品詳細・編集' })).toBeVisible();
  await expect(page.locator('input[name="product_name"]')).toHaveValue(productName);
  await expect(page.locator('body')).not.toContainText('JAN / EAN');
  await expect(page.locator('body')).not.toContainText('ISBN');
  await expect(page.locator('body')).not.toContainText('製造番号');

  await page.locator('input[name="confirm_deletion"]').check();
  await page.getByRole('button', { name: '商品を削除' }).click();
  await expect(page).toHaveURL(/\/products$/);
  await expect(page.locator('article').filter({ hasText: productName })).toHaveCount(0);
});
test('user can upload multiple product images during registration', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  const images = Array.from({ length: 10 }, (_, index) => ({
    name: `e2e-image-${index + 1}.png`,
    mimeType: 'image/png',
    buffer: png,
  }));
  await page.locator('#product-images').setInputFiles(images);
  await expect(page.locator('#product-image-preview img')).toHaveCount(10);

  await page.locator('input[name="internal_sku"]').fill(`E2E-IMAGE-${Date.now()}`);
  await page.locator('input[name="product_name"]').fill('E2E画像商品');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await openProductEditByName(page, 'E2E画像商品');
  await expect(page.locator('#stored-product-images .stored-product-image')).toHaveCount(10);
});
test('mobile camera capture is added to the product image list while the camera action stays hidden on desktop', async ({ page, browser }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const cameraButton = page.locator('#product-image-camera-choose');
  await expect(cameraButton).toBeHidden();
  await expect(page.locator('.product-image-camera-help')).toBeHidden();
  await expect(page.locator('#product-image-camera')).toHaveAttribute('capture', 'environment');
  const desktopImageStyles = await page.locator('#product-image-drop-zone').evaluate((element) => ({
    padding: getComputedStyle(element).padding,
    cameraButtonDisplay: getComputedStyle(element.querySelector('#product-image-camera-choose')).display,
  }));
  expect(desktopImageStyles).toEqual({ padding: '24px', cameraButtonDisplay: 'none' });

  const mobileContext = await browser.newContext({
    baseURL: new URL(page.url()).origin,
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });
  try {
    const mobilePage = await mobileContext.newPage();
    await mobilePage.goto('/login');
    await mobilePage.getByRole('button', { name: 'デモを見る' }).click();
    await expect(mobilePage).toHaveURL(/dashboard/);
    const mobileDashboardDetails = mobilePage.locator('#furimadeck-dashboard details.dashboard-mobile-collapsible');
    await mobileDashboardDetails.first().waitFor();
    expect(await mobileDashboardDetails.count()).toBeGreaterThan(5);
    expect(await mobileDashboardDetails.first().evaluate((element) => element.open)).toBe(false);
    await expect(mobileDashboardDetails.first().locator('summary')).toBeVisible();
    const dashboardHeights = await mobileDashboardDetails.evaluateAll((sections) => {
      const collapsedHeight = document.documentElement.scrollHeight;
      sections.forEach((section) => { section.open = true; });
      const expandedHeight = document.documentElement.scrollHeight;
      sections.forEach((section) => { section.open = false; });

      return { collapsedHeight, expandedHeight };
    });
    expect(dashboardHeights.collapsedHeight).toBeLessThan(dashboardHeights.expandedHeight);
    await mobileDashboardDetails.first().locator('summary').click();
    expect(await mobileDashboardDetails.first().evaluate((element) => element.open)).toBe(true);
    await expect(mobileDashboardDetails.first().locator('.dashboard-mobile-content')).toBeVisible();
    const mobileDashboardOverflow = await mobilePage.locator('#furimadeck-dashboard').evaluate((element) => ({
      scrollWidth: element.scrollWidth,
      clientWidth: element.clientWidth,
    }));
    expect(mobileDashboardOverflow.scrollWidth).toBeLessThanOrEqual(mobileDashboardOverflow.clientWidth);
    const monthlyChartDetails = mobileDashboardDetails.filter({ hasText: '利益グラフ' }).first();
    await monthlyChartDetails.locator('summary').click();
    const monthlyChartCanvas = mobilePage.locator('#furimadeckMonthlySalesChart');
    await expect(monthlyChartCanvas).toBeVisible();
    await expect.poll(() => monthlyChartCanvas.evaluate((canvas) => canvas.getBoundingClientRect().width)).toBeGreaterThan(0);
    const chartState = await monthlyChartCanvas.evaluate((canvas) => ({
      chartInitialized: Boolean(window.Chart?.getChart(canvas)),
      width: canvas.getBoundingClientRect().width,
      height: canvas.getBoundingClientRect().height,
    }));
    expect(chartState.chartInitialized).toBe(true);
    expect(chartState.width).toBeGreaterThan(0);
    expect(chartState.height).toBeGreaterThan(0);
    await mobilePage.goto('/products/create');
    const mobileCameraButton = mobilePage.locator('#product-image-camera-choose');
    await expect(mobileCameraButton).toBeVisible();
    await expect(mobilePage.locator('.product-image-camera-help')).toBeVisible();
    const mobileImageStyles = await mobilePage.locator('#product-image-drop-zone').evaluate((element) => ({
      padding: getComputedStyle(element).padding,
      cameraButtonDisplay: getComputedStyle(element.querySelector('#product-image-camera-choose')).display,
      touchPoints: navigator.maxTouchPoints,
    }));
    expect(mobileImageStyles).toEqual({ padding: '16px', cameraButtonDisplay: 'flex', touchPoints: 1 });
    await expect(mobilePage.locator('#product-image-camera')).toHaveAttribute('capture', 'environment');

    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await mobilePage.locator('#product-image-camera').setInputFiles({
      name: 'e2e-camera-capture.png',
      mimeType: 'image/png',
      buffer: png,
    });
    await expect(mobilePage.locator('#product-image-preview img')).toHaveCount(1);
    await expect.poll(() => mobilePage.locator('#product-images').evaluate((input) => input.files.length)).toBe(1);
  } finally {
    await mobileContext.close();
  }
});
test('user can reorder stored product images by dragging', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.locator('#product-images').setInputFiles([
    { name: 'e2e-reorder-1.png', mimeType: 'image/png', buffer: png },
    { name: 'e2e-reorder-2.png', mimeType: 'image/png', buffer: png },
  ]);
  await page.locator('input[name="internal_sku"]').fill(`E2E-REORDER-${Date.now()}`);
  await page.locator('input[name="product_name"]').fill('E2E並び替え商品');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await openProductEditByName(page, 'E2E並び替え商品');
  const images = page.locator('#stored-product-images .stored-product-image');
  await expect(images).toHaveCount(2);
  const firstId = await images.nth(0).getAttribute('data-image-id');
  const secondId = await images.nth(1).getAttribute('data-image-id');
  await images.nth(1).dragTo(images.nth(0));
  await expect.poll(async () => images.nth(0).getAttribute('data-image-id')).toBe(secondId);
  await expect(images.nth(1)).toHaveAttribute('data-image-id', firstId);
});
test('authenticated user can register a listing without exposing accounting amounts', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-LISTING-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E出品商品');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await page.goto('/listings/create');
  const productOption = page.locator('select[name="product_id"] option', { hasText: 'E2E出品商品' });
  await expect(productOption).toHaveCount(1);
  await page.locator('select[name="product_id"]').selectOption(await productOption.getAttribute('value'));
  await page.locator('select[name="marketplace_id"]').selectOption({ index: 1 });
  await page.locator('input[name="listing_title"]').fill('E2E出品タイトル');
  await page.locator('input[name="listing_price"]').fill('5000');
  await page.locator('input[name="expected_fee_rate"]').fill('10');
  await page.locator('input[name="shipping_fee"]').fill('750');
  await page.locator('select[name="status"]').selectOption('active');
  await expect(page.locator('#listing-profit-estimate')).toHaveCount(0);
  await page.getByRole('button', { name: '出品情報を登録' }).click();

  await expect(page).toHaveURL(/\/listings\/\d+\/edit/);
  await expect(page.locator('input[name="listing_title"]')).toHaveValue('E2E出品タイトル');

  await page.goto('/products');
  const productCard = page.locator('article').filter({ hasText: 'E2E出品商品' });
  await expect(productCard.getByText('出品価格', { exact: true })).toBeVisible();
  await expect(productCard.getByText('¥5,000', { exact: true })).toBeVisible();
});
test('authenticated user can record a sale without entering accounting amounts on the sales screen', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-SALE-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E販売商品');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await setPurchaseInfo(page, 'E2E販売商品', 1000);
  await createListingForProduct(page, 'E2E販売商品', 'E2E販売出品');
  await openSaleCreateForProduct(page, 'E2E販売商品');
  await expect(page.locator('input[name="cost_basis"]')).toHaveCount(0);
  await page.locator('input[name="sold_price"]').fill('5000');
  await page.locator('input[name="shipping_fee"]').fill('750');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByText('E2E販売商品')).toBeVisible();
  await page.goto('/furimadeck-sales');
  const saleRow = page.getByRole('row').filter({ hasText: 'E2E販売商品' });
  await expect(saleRow).toContainText('¥5,000');
  await expect(saleRow).not.toContainText('¥2,750');
});
test('recorded sale can advance through shipment and completion', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-STATUS-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E状態遷移商品');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await setPurchaseInfo(page, 'E2E状態遷移商品', 1000);
  await createListingForProduct(page, 'E2E状態遷移商品', 'E2E状態遷移出品');
  await openSaleCreateForProduct(page, 'E2E状態遷移商品');
  await page.locator('input[name="sold_price"]').fill('4000');
  await page.locator('input[name="shipping_fee"]').fill('600');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await page.goto('/furimadeck-sales');
  const saleRow = page.getByRole('row').filter({ hasText: 'E2E状態遷移商品' });
  await expect(saleRow).toContainText('¥4,000');
  await saleRow.getByRole('button', { name: '発送済みにする' }).click();
  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  await expect(saleRow).toContainText('発送済み');
  await saleRow.getByRole('button', { name: '取引を完了する' }).click();
  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  await expect(page.getByRole('row').filter({ hasText: 'E2E状態遷移商品' })).toContainText('取引完了');
});
test('recorded sale can be cancelled with a reason', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-CANCEL-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E取消商品');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await setPurchaseInfo(page, 'E2E取消商品', 1000);
  await createListingForProduct(page, 'E2E取消商品', 'E2E取消出品');
  await openSaleCreateForProduct(page, 'E2E取消商品');
  await page.locator('input[name="sold_price"]').fill('3000');
  await page.locator('input[name="shipping_fee"]').fill('500');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await page.goto('/furimadeck-sales');
  const saleRow = page.getByRole('row').filter({ hasText: 'E2E取消商品' });
  await saleRow.getByPlaceholder('キャンセル理由').fill('購入者都合のため取消');
  await saleRow.getByRole('button', { name: '取消' }).click();
  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  await expect(page.getByRole('row').filter({ hasText: 'E2E取消商品' })).toContainText('取消済み');
});
test('recorded sale can be returned and restocked', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-RETURN-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E返品商品');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await setPurchaseInfo(page, 'E2E返品商品', 1000);
  await createListingForProduct(page, 'E2E返品商品', 'E2E返品出品');
  await openSaleCreateForProduct(page, 'E2E返品商品');
  await page.locator('input[name="sold_price"]').fill('3500');
  await page.locator('input[name="shipping_fee"]').fill('600');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await page.goto('/furimadeck-sales');
  const saleRow = page.getByRole('row').filter({ hasText: 'E2E返品商品' });
  await saleRow.getByText('返品・返金を記録').click();
  await saleRow.getByPlaceholder('返品理由').fill('購入者から返品されたため');
  await saleRow.locator('input[name="refund_amount"]').fill('3500');
  await saleRow.locator('input[name="return_shipping_fee"]').fill('600');
  await saleRow.getByRole('checkbox', { name: '在庫へ戻す' }).check();
  page.once('dialog', (dialog) => dialog.accept());
  await saleRow.getByRole('button', { name: '返品を記録' }).click();
  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  await expect(page.getByRole('row').filter({ hasText: 'E2E返品商品' })).toContainText('返品・返金済み');
});
test('authenticated major FurimaDeck screens respond successfully', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  for (const path of [
    '/dashboard',
    '/furimadeck-dashboard',
    '/products',
    '/listings',
    '/furimadeck-sales',
    '/furimadeck-analytics',
    '/furimadeck-analytics/advanced',
    '/furimadeck-improvement',
    '/furimadeck-accounting',
    '/furimadeck-account',
  ]) {
    const response = await page.goto(path);
    expect(response?.status(), `${path} response`).toBe(200);
    await expect(page.locator('body')).not.toContainText('Internal Server Error');
    if (path === '/furimadeck-accounting') {
      await expect(page.locator('header h2')).toHaveClass(/text-slate-900/);
      await expect(page.locator('header p')).toHaveClass(/text-slate-600/);
      const headingColor = await page.locator('header h2').evaluate((element) => getComputedStyle(element).color);
      const sectionColor = await page.locator('header p').evaluate((element) => getComputedStyle(element).color);
      expect(headingColor).not.toBe('rgb(255, 255, 255)');
      expect(sectionColor).not.toBe('rgb(255, 255, 255)');
    }
  }
});
test('dashboard, products, and accounting stay within a mobile viewport', async ({ page }, testInfo) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  for (const path of ['/dashboard', '/products', '/furimadeck-accounting']) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(path);
    if (path === '/dashboard') {
      const shell = page.locator('.furimadeck-app-shell');
      const shellHeight = await shell.evaluate((element) => element.getBoundingClientRect().height);
      const documentHeight = await page.evaluate(() => document.documentElement.scrollHeight);
      const shellBackground = await shell.evaluate((element) => getComputedStyle(element).backgroundColor);
      expect(shellHeight, `dashboard shell ${shellHeight}px should cover page ${documentHeight}px`).toBeGreaterThanOrEqual(documentHeight - 1);
      expect(shellBackground).not.toBe('rgb(255, 255, 255)');

      const desktopStyles = await page.locator('#furimadeck-dashboard').evaluate((element) => ({
        paddingTop: getComputedStyle(element).paddingTop,
        backgroundAttachment: getComputedStyle(element).backgroundAttachment,
        smallTextFontSize: getComputedStyle(element.querySelector('.text-xs')).fontSize,
        chartHeight: getComputedStyle(element.querySelector('.furimadeck-dashboard-chart')).height,
        monthNavigationDisplay: getComputedStyle(element.querySelector('nav[aria-label="月次グラフの表示月移動"]')).display,
        lastPortfolioCardGridColumnStart: getComputedStyle(element.querySelector('.furimadeck-dashboard-portfolio > article:last-child')).gridColumnStart,
      }));
      expect(desktopStyles).toEqual({
        paddingTop: '32px',
        backgroundAttachment: 'fixed, fixed',
        smallTextFontSize: '12px',
        chartHeight: '384px',
        monthNavigationDisplay: 'flex',
        lastPortfolioCardGridColumnStart: 'auto',
      });

      await page.setViewportSize({ width: 640, height: 900 });
      const nonMobileBoundaryStyles = await page.evaluate(() => ({
        mobileColorToken: getComputedStyle(document.documentElement).getPropertyValue('--color-fd-primary').trim(),
        dashboardPaddingTop: getComputedStyle(document.querySelector('#furimadeck-dashboard')).paddingTop,
        mobileSummaryDisplay: getComputedStyle(document.querySelector('#furimadeck-dashboard .dashboard-mobile-summary')).display,
      }));
      expect(nonMobileBoundaryStyles).toEqual({ mobileColorToken: '', dashboardPaddingTop: '32px', mobileSummaryDisplay: 'none' });

      await page.setViewportSize({ width: 639, height: 900 });
      const mobileBoundaryStyles = await page.evaluate(() => ({
        mobileColorToken: getComputedStyle(document.documentElement).getPropertyValue('--color-fd-primary').trim(),
        dashboardPaddingTop: getComputedStyle(document.querySelector('#furimadeck-dashboard')).paddingTop,
        mobileSummaryDisplay: getComputedStyle(document.querySelector('#furimadeck-dashboard .dashboard-mobile-summary')).display,
      }));
      expect(mobileBoundaryStyles).toEqual({ mobileColorToken: '#334155', dashboardPaddingTop: '16px', mobileSummaryDisplay: 'flex' });
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    await page.screenshot({ path: testInfo.outputPath(`${path.slice(1).replaceAll('/', '-')}-desktop.png`), fullPage: true });
    if (path === '/dashboard') {
      await page.evaluate(() => window.scrollTo(0, 900));
      await page.screenshot({ path: testInfo.outputPath('dashboard-scrolled.png') });
    }

    for (const viewport of [
      { width: 360, height: 800 },
      { width: 390, height: 844 },
      { width: 768, height: 1024 },
    ]) {
      await page.setViewportSize(viewport);
      const viewportHasNoOverflow = await page.waitForFunction(
        () => document.documentElement.scrollWidth <= window.innerWidth,
        undefined,
        { timeout: 5000 },
      ).then(() => true).catch(() => false);
      if (!viewportHasNoOverflow) {
        const overflowDetails = await page.evaluate(() => {
          const viewportWidth = window.innerWidth;
          const elements = Array.from(document.body.querySelectorAll('*'))
            .map((element) => {
              const rect = element.getBoundingClientRect();
              const className = typeof element.className === 'string' ? element.className : '';
              return {
                selector: `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''}${className ? `.${className.trim().split(/\\s+/).slice(0, 3).join('.')}` : ''}`,
                left: Math.round(rect.left),
                right: Math.round(rect.right),
                width: Math.round(rect.width),
              };
            })
            .filter((element) => element.right > viewportWidth || element.left < 0)
            .slice(0, 8);
          return { viewportWidth, documentWidth: document.documentElement.scrollWidth, elements };
        });
        expect(viewportHasNoOverflow, `Horizontal overflow on ${path} at ${viewport.width}px: ${JSON.stringify(overflowDetails)}`).toBe(true);
      }
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => window.scrollTo(0, 0));
    if (path === '/dashboard') {
      const mobileStyles = await page.locator('#furimadeck-dashboard').evaluate((element) => ({
        paddingTop: getComputedStyle(element).paddingTop,
        backgroundAttachment: getComputedStyle(element).backgroundAttachment,
        smallTextFontSize: getComputedStyle(element.querySelector('.text-xs')).fontSize,
        chartHeight: getComputedStyle(element.querySelector('.furimadeck-dashboard-chart')).height,
        monthNavigationDisplay: getComputedStyle(element.querySelector('nav[aria-label="月次グラフの表示月移動"]')).display,
        lastPortfolioCardGridColumnStart: getComputedStyle(element.querySelector('.furimadeck-dashboard-portfolio > article:last-child')).gridColumnStart,
      }));
      expect(mobileStyles).toEqual({
        paddingTop: '16px',
        backgroundAttachment: 'scroll',
        smallTextFontSize: '14px',
        chartHeight: '256px',
        monthNavigationDisplay: 'grid',
        lastPortfolioCardGridColumnStart: 'span 2',
      });
      await page.screenshot({ path: testInfo.outputPath('dashboard-mobile-viewport.png') });
      await page.evaluate(() => window.scrollTo(0, 1000));
      await page.screenshot({ path: testInfo.outputPath('dashboard-mobile-middle-viewport.png') });
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    if (path === '/products') {
      const menuButton = page.getByRole('button', { name: 'メニューを開く' });
      await expect(menuButton).toBeVisible();
      await menuButton.click();
      await expect(page.locator('nav').getByRole('link', { name: '商品を登録' })).toBeVisible();
    }
    await page.screenshot({ path: testInfo.outputPath(`${path.slice(1).replaceAll('/', '-')}-mobile.png`), fullPage: true });
  }
});

test('iOS users see instructions for adding FurimaDeck to the Home Screen', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'userAgent', {
      configurable: true,
      get: () => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
    });
    Object.defineProperty(navigator, 'platform', { configurable: true, get: () => 'iPhone' });
  });

  await page.goto('/login');
  const instructions = page.locator('[data-pwa-ios-install-help]');
  await expect(instructions).toBeVisible();
  await instructions.locator('summary').click();
  await expect(instructions).toContainText('共有ボタン');
  await expect(instructions).toContainText('ホーム画面に追加');
});

test('PWA manifest icons and the root-scoped service worker are reachable', async ({ page }) => {
  await page.goto('/login');

  const manifestResponse = await page.request.get(new URL('/manifest.webmanifest', page.url()).toString());
  expect(manifestResponse.status()).toBe(200);
  expect(manifestResponse.headers()['content-type']).toContain('application/manifest+json');
  const manifest = await manifestResponse.json();
  expect(manifest.display).toBe('standalone');
  expect(manifest.scope).toBe('/');
  expect(manifest.icons).toHaveLength(2);

  for (const icon of manifest.icons) {
    const iconResponse = await page.request.get(new URL(icon.src, page.url()).toString());
    expect(iconResponse.status(), `${icon.src} should be served`).toBe(200);
    expect(iconResponse.headers()['content-type']).toContain('image/png');
  }

  const serviceWorkerResponse = await page.request.get(new URL('/service-worker.js', page.url()).toString());
  expect(serviceWorkerResponse.status()).toBe(200);
  expect(serviceWorkerResponse.headers()['content-type']).toContain('application/javascript');
  expect(serviceWorkerResponse.headers()['service-worker-allowed']).toBe('/');
});

test('PWA activation removes only FurimaDeck cache entries', async ({ page }) => {
  await page.addInitScript(() => {
    const serviceWorkerContainer = navigator.serviceWorker;
    const register = serviceWorkerContainer.register.bind(serviceWorkerContainer);
    Object.defineProperty(serviceWorkerContainer, 'register', {
      configurable: true,
      value: async (...args) => {
        const unrelatedCache = await caches.open('other-app-cache-v1');
        await unrelatedCache.put('/other-app-cache-entry', new Response('keep'));
        const previousFurimaDeckCache = await caches.open('furimadeck-pwa-v2');
        await previousFurimaDeckCache.put('/old-furimadeck-cache-entry', new Response('remove'));

        return register(...args);
      },
    });
  });

  await page.goto('/login');
  await page.evaluate(async () => navigator.serviceWorker.ready);

  await expect.poll(() => page.evaluate(() => caches.keys())).toEqual(
    expect.arrayContaining(['other-app-cache-v1', 'furimadeck-pwa-v3'])
  );
  await expect.poll(() => page.evaluate(() => caches.keys())).not.toContain('furimadeck-pwa-v2');
});

test('PWA static assets remain available offline after the network response', async ({ page }) => {
  const assetPath = '/images/logo.png?e2e-pwa-cache=1';

  await page.goto('/login');
  await page.evaluate(async () => navigator.serviceWorker.ready);
  await page.reload();
  await page.waitForFunction(() => navigator.serviceWorker.controller !== null);

  const networkStatus = await page.evaluate(async (path) => (await fetch(path)).status, assetPath);
  expect(networkStatus).toBe(200);

  await expect.poll(async () => page.evaluate(async (path) => {
    const request = new Request(new URL(path, window.location.origin));

    return Boolean(await caches.match(request));
  }, assetPath)).toBe(true);

  await page.context().setOffline(true);
  try {
    const offlineStatus = await page.evaluate(async (path) => {
      try {
        return (await fetch(path)).status;
      } catch {
        return 0;
      }
    }, assetPath);
    expect(offlineStatus).toBe(200);
  } finally {
    await page.context().setOffline(false);
  }
});

test('product list search filters by product name', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/import');
  const csv = [
    'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
    'E2E-SEARCH-A,検索対象商品A,used,700,1,1,in_stock',
    'E2E-SEARCH-B,検索対象商品B,used,900,1,1,in_stock',
  ].join('\n');
  await page.locator('form[action*="/products/import/preview"] input[type="file"]').setInputFiles({
    name: 'e2e-search.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv, 'utf8'),
  });
  await page.getByRole('button', { name: 'CSVを検証して登録確認へ' }).click();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: '2件を確定登録' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await page.locator('input#keyword').fill('検索対象商品A');
  await page.getByRole('button', { name: '検索' }).click();
  await expect(page).toHaveURL(/\/products\?keyword=/);
  await expect(page.getByText('検索対象商品A')).toBeVisible();
  await expect(page.getByText('検索対象商品B')).toHaveCount(0);
});
test('accounting CSV section downloads the common CSV and hides provider exports while disconnected', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/furimadeck-accounting?section=csv&from=2000-01-01&to=2000-01-02');
  await expect(page.getByRole('heading', { name: '会計データ' }).first()).toBeVisible();
  const commonCsv = page.getByRole('link', { name: '共通CSVをダウンロード' });
  await expect(commonCsv).toBeVisible();
  const downloadResponsePromise = page.waitForResponse((response) => response.url().includes('/furimadeck-accounting.csv'));
  await commonCsv.click();
  const downloadResponse = await downloadResponsePromise;
  expect(downloadResponse.status()).toBe(200);
  expect(downloadResponse.headers()['content-type']).toContain('text/csv');

  await expect(page.getByText('freee連携が未接続または期限切れのため、一括CSVを出力できません。')).toBeVisible();
  await expect(page.getByText('Money Forward連携が未接続または期限切れのため、一括CSVを出力できません。')).toBeVisible();
  await expect(page.getByRole('button', { name: 'freee CSV一括出力' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Money Forward CSV一括出力' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'CSV出力先設定を確認' })).toHaveCount(2);
});
test('product CSV import stops at preview before commit', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/import');
  const csv = [
    'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
    'E2E-CSV-001,CSVプレビュー商品,used,900,1,1,in_stock',
  ].join('\n');
  await page.locator('form[action*="/products/import/preview"] input[type="file"]').setInputFiles({
    name: 'e2e-product.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv, 'utf8'),
  });
  await page.getByRole('button', { name: 'CSVを検証して登録確認へ' }).click();

  await expect(page).toHaveURL(/\/products\/import\/\d+/);
  await expect(page.getByRole('heading', { name: 'CSV取込プレビュー' })).toBeVisible();
  await expect(page.getByText('CSVプレビュー商品')).toBeVisible();
  await expect(page.getByText('有効 1行')).toBeVisible();
  await expect(page.getByRole('button', { name: '1件を確定登録' })).toBeVisible();
});
test('product CSV preview can be committed after review', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/import');
  const csv = [
    'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
    'E2E-CSV-COMMIT-001,CSV確定登録商品,used,1500,1,1,in_stock',
  ].join('\n');
  await page.locator('form[action*="/products/import/preview"] input[type="file"]').setInputFiles({
    name: 'e2e-product-commit.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv, 'utf8'),
  });
  await page.getByRole('button', { name: 'CSVを検証して登録確認へ' }).click();
  await expect(page.getByRole('heading', { name: 'CSV取込プレビュー' })).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: '1件を確定登録' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByText('CSV確定登録商品')).toBeVisible();
  await expect(page.getByText('E2E-CSV-COMMIT-001')).toBeVisible();
});
test('authenticated CSV management screen exposes and downloads the required exports', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/import');
  await expect(page.getByRole('heading', { name: 'CSV管理' })).toBeVisible();

  for (const name of ['販売CSV', '全商品バックアップCSV', '復元用CSV', '出品中のみCSV']) {
    const link = page.getByRole('link', { name });
    await expect(link).toBeVisible();
    const exportResponsePromise = page.waitForResponse((response) => response.url().includes('/furimadeck-export/'));
    await link.click();
    const exportResponse = await exportResponsePromise;
    expect(exportResponse.status()).toBe(200);
    expect(exportResponse.headers()['content-type']).toContain('text/csv');
    await page.goto('/products/import');
  }
});
