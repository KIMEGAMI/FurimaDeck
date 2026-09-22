import { expect, test } from '@playwright/test';

async function openProductEditByName(page, productName) {
  await page.goto(`/products?keyword=${encodeURIComponent(productName)}`);
  await expect(page.getByText(productName).first()).toBeVisible();
  const editHref = await page.locator('a[href*="/products/"][href$="/edit"]').first().getAttribute('href');
  expect(editHref).toBeTruthy();
  await page.goto(editHref);
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

test('login page renders the authentication controls', async ({ page }) => {
  await page.goto('/login');
  await expect(page).toHaveTitle(/FurimaDeck/i);
  await expect(page.locator('input#email')).toBeVisible();
  await expect(page.locator('input#password')).toBeVisible();
  await expect(page.getByRole('button', { name: 'ログイン' })).toBeVisible();
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

  await page.goto('/products/create');
  console.log('E2E_PRODUCTS_CREATE', page.url(), await page.title(), (await page.locator('body').innerText()).slice(0, 240));
  const sku = `E2E-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2Eテスト商品');
  await page.locator('input[name="purchase_unit_cost"]').fill('1200');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByText(sku)).toBeVisible();
  await expect(page.getByText('E2Eテスト商品').first()).toBeVisible();
});
test('user can upload multiple product images during registration', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.locator('#product-images').setInputFiles([
    { name: 'e2e-image-1.png', mimeType: 'image/png', buffer: png },
    { name: 'e2e-image-2.png', mimeType: 'image/png', buffer: png },
  ]);
  await expect(page.locator('#product-image-preview img')).toHaveCount(2);

  await page.locator('input[name="internal_sku"]').fill(`E2E-IMAGE-${Date.now()}`);
  await page.locator('input[name="product_name"]').fill('E2E画像商品');
  await page.locator('input[name="purchase_unit_cost"]').fill('800');
  await page.getByRole('button', { name: '登録する' }).click();

  await expect(page).toHaveURL(/\/products$/);
  await openProductEditByName(page, 'E2E画像商品');
  await expect(page.locator('#stored-product-images .stored-product-image')).toHaveCount(2);
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
  await page.locator('input[name="purchase_unit_cost"]').fill('800');
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
test('authenticated user can register a listing with a profit estimate', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-LISTING-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E出品商品');
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
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
  await expect(page.locator('#listing-profit-estimate')).toContainText('¥2,750');
  await page.getByRole('button', { name: '出品情報を登録' }).click();

  await expect(page).toHaveURL(/\/listings\/\d+\/edit/);
  await expect(page.locator('input[name="listing_title"]')).toHaveValue('E2E出品タイトル');
});
test('authenticated user can record a sale with the calculated profit', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-SALE-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E販売商品');
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await createListingForProduct(page, 'E2E販売商品', 'E2E販売出品');
  await page.goto('/furimadeck-sales/create');
  const productOption = page.locator('select[name="listing_id"] option', { hasText: 'E2E販売商品' });
  await expect(productOption).not.toHaveCount(0);
  await page.locator('select[name="listing_id"]').selectOption(await productOption.last().getAttribute('value'));
  await page.locator('input[name="quantity"]').fill('1');
  await page.locator('input[name="sold_price"]').fill('5000');
  await page.locator('input[name="cost_basis"]').fill('1000');
  await page.locator('input[name="shipping_fee"]').fill('750');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  await expect(page.getByText('E2E販売商品')).toBeVisible();
  await expect(page.getByText('¥2,750')).toBeVisible();
});
test('recorded sale can advance through shipment and completion', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/products/create');
  const sku = `E2E-STATUS-${Date.now()}`;
  await page.locator('input[name="internal_sku"]').fill(sku);
  await page.locator('input[name="product_name"]').fill('E2E状態遷移商品');
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await createListingForProduct(page, 'E2E状態遷移商品', 'E2E状態遷移出品');
  await page.goto('/furimadeck-sales/create');
  const productOption = page.locator('select[name="listing_id"] option', { hasText: 'E2E状態遷移商品' });
  await expect(productOption).not.toHaveCount(0);
  await page.locator('select[name="listing_id"]').selectOption(await productOption.last().getAttribute('value'));
  await page.locator('input[name="quantity"]').fill('1');
  await page.locator('input[name="sold_price"]').fill('4000');
  await page.locator('input[name="cost_basis"]').fill('1000');
  await page.locator('input[name="shipping_fee"]').fill('600');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/furimadeck-sales$/);
  const saleRow = page.getByRole('row').filter({ hasText: 'E2E状態遷移商品' });
  await expect(saleRow).toContainText('¥2,000');
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
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await createListingForProduct(page, 'E2E取消商品', 'E2E取消出品');
  await page.goto('/furimadeck-sales/create');
  const productOption = page.locator('select[name="listing_id"] option', { hasText: 'E2E取消商品' });
  await expect(productOption).not.toHaveCount(0);
  await page.locator('select[name="listing_id"]').selectOption(await productOption.last().getAttribute('value'));
  await page.locator('input[name="quantity"]').fill('1');
  await page.locator('input[name="sold_price"]').fill('3000');
  await page.locator('input[name="cost_basis"]').fill('1000');
  await page.locator('input[name="shipping_fee"]').fill('500');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/furimadeck-sales$/);
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
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await createListingForProduct(page, 'E2E返品商品', 'E2E返品出品');
  await page.goto('/furimadeck-sales/create');
  const productOption = page.locator('select[name="listing_id"] option', { hasText: 'E2E返品商品' });
  await expect(productOption).not.toHaveCount(0);
  await page.locator('select[name="listing_id"]').selectOption(await productOption.last().getAttribute('value'));
  await page.locator('input[name="quantity"]').fill('1');
  await page.locator('input[name="sold_price"]').fill('3500');
  await page.locator('input[name="cost_basis"]').fill('1000');
  await page.locator('input[name="shipping_fee"]').fill('600');
  await page.getByRole('button', { name: '販売を確定する' }).click();

  await expect(page).toHaveURL(/\/furimadeck-sales$/);
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
test('accounting screen downloads all supported CSV formats', async ({ page }) => {
  await page.goto('/login');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/dashboard/);

  await page.goto('/furimadeck-accounting');
  await expect(page.getByRole('heading', { name: '確定申告・会計連携' })).toBeVisible();
  for (const name of ['共通CSVをダウンロード', 'freee CSV', 'Money Forward CSV']) {
    const link = page.getByRole('link', { name });
    await expect(link).toBeVisible();
    const downloadResponsePromise = page.waitForResponse((response) => response.url().includes('/furimadeck-accounting'));
    await link.click();
    const downloadResponse = await downloadResponsePromise;
    expect(downloadResponse.status()).toBe(200);
    expect(downloadResponse.headers()['content-type']).toContain('text/csv');
    await page.goto('/furimadeck-accounting');
  }
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

  for (const name of ['販売CSV', '完全バックアップCSV', '復元用CSV', '出品中のみCSV']) {
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
