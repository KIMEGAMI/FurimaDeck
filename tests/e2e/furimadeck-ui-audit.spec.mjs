import { mkdirSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { expect, test } from '@playwright/test';

const evidenceRoot = resolve('storage/framework/testing');
const requestedEvidenceDirectory = process.env.FURIMADECK_UI_AUDIT_EVIDENCE_DIRECTORY
  ?? 'storage/framework/testing/furimadeck-ui-audit-evidence';
const evidenceDirectory = resolve(requestedEvidenceDirectory);
if (evidenceDirectory !== evidenceRoot && !evidenceDirectory.startsWith(`${evidenceRoot}${sep}`)) {
  throw new Error('UI audit evidence must stay under storage/framework/testing.');
}
const captureEvidence = process.env.FURIMADECK_UI_AUDIT_CAPTURE === '1';
if (captureEvidence) mkdirSync(evidenceDirectory, { recursive: true });

async function capture(page, filename, fullPage = false) {
  if (!captureEvidence) return;
  await page.screenshot({ path: resolve(evidenceDirectory, filename), fullPage });
}

test('capture the end-to-end FurimaDeck UI using synthetic data only', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });

  const publicResponse = await page.goto('/');
  expect(publicResponse?.status()).toBe(200);
  await capture(page, '01-public-home.png');

  await page.goto('/login');
  await capture(page, '02-login.png');
  await page.getByRole('button', { name: 'デモを見る' }).click();
  await expect(page).toHaveURL(/furimadeck-dashboard/);
  await capture(page, '03-dashboard.png', true);

  await page.goto('/products/create');
  await expect(page.getByRole('heading', { name: /商品/ }).first()).toBeVisible();
  await expect(page.getByText('登録後は商品詳細画面の「出品先・出品価格」から確認・追加できます。')).toBeVisible();
  await expect(page.locator('.category-level-select')).toHaveCount(1);
  await page.locator('.category-level-select').nth(0).selectOption({ label: 'メンズ' });
  await expect(page.locator('.category-level-select')).toHaveCount(2);
  await page.locator('.category-level-select').nth(1).selectOption({ label: 'トップス' });
  await expect(page.locator('.category-level-select')).toHaveCount(3);
  await page.locator('.category-level-select').nth(2).selectOption({ label: 'Tシャツ/カットソー(半袖/袖なし)' });
  await expect(page.locator('#category_id')).not.toHaveValue('');
  await capture(page, '04b-product-create-category.png', true);
  await capture(page, '04-product-create.png', true);
  await page.locator('input[name="internal_sku"]').fill('UI-AUDIT-001');
  await page.locator('input[name="product_name"]').fill('UI監査用テスト商品');
  await page.locator('input[name="purchase_date"]').fill('2026-10-12');
  await page.locator('input[name="purchase_unit_cost"]').fill('1000');
  await page.locator('input[name="purchase_shipping_cost"]').fill('100');
  await page.locator('input[name="other_purchase_expense"]').fill('50');
  await page.getByRole('button', { name: '登録する' }).click();
  await expect(page).toHaveURL(/\/products$/);
  await capture(page, '05-products-list.png', true);

  const productCard = page.locator('article').filter({ hasText: 'UI監査用テスト商品' });
  const productHref = await productCard.locator('a[href*="/products/"]').first().getAttribute('href');
  const productId = productHref?.match(/\/products\/(\d+)/)?.[1];
  expect(productId).toBeTruthy();
  await page.goto(`/products/${productId}`);
  await expect(page.getByRole('heading', { name: '商品詳細・編集' })).toBeVisible();
  await capture(page, '06-product-detail-edit.png', true);

  await page.goto(`/products/${productId}/edit`);
  await expect(page.locator('input[name="purchase_date"]')).toHaveValue('2026-10-12');
  await expect(page.locator('input[name="purchase_unit_cost"]')).toHaveValue('1000');
  await expect(page.locator('input[name="purchase_shipping_cost"]')).toHaveValue('100');
  await expect(page.locator('input[name="other_purchase_expense"]')).toHaveValue('50');
  await page.locator('input[name="purchase_unit_cost"]').fill('1200');
  await page.getByRole('button', { name: '更新する' }).click();
  await expect(page).toHaveURL(/\/products$/);
  await expect(page.getByText('商品を更新しました。')).toBeVisible();
  await page.goto(`/products/${productId}/edit`);
  await expect(page.locator('input[name="purchase_unit_cost"]')).toHaveValue('1200');

  await page.goto('/listings/create');
  await page.locator('select[name="product_id"]').selectOption(productId);
  await page.locator('select[name="marketplace_id"]').selectOption({ index: 1 });
  await page.locator('input[name="listing_title"]').fill('UI監査用テスト商品');
  await page.locator('input[name="listing_price"]').fill('5000');
  await page.locator('input[name="expected_fee_rate"]').fill('10');
  await page.locator('input[name="shipping_fee"]').fill('750');
  await page.locator('select[name="status"]').selectOption('active');
  await capture(page, '07-listing-create.png', true);
  await page.getByRole('button', { name: '出品情報を登録' }).click();
  await expect(page).toHaveURL(/\/listings\/\d+\/edit/);

  await page.goto(`/furimadeck-sales/create?product_id=${productId}`);
  await expect(page.locator('input[name="sold_price"]')).toHaveValue('5000');
  await capture(page, '08-sale-record.png', true);
  await page.getByRole('button', { name: '販売を確定する' }).click();
  await expect(page).toHaveURL(/\/products$/);

  await page.goto('/furimadeck-accounting?section=csv');
  await page.getByRole('button', { name: '確認用データを更新' }).click();
  await expect(page.getByText('共通会計データを更新しました。内容を確認してからCSVをダウンロードしてください。')).toBeVisible();

  const auditPages = [
    ['/furimadeck-sales', '09-sales-list.png'],
    ['/furimadeck-analytics', '10-sales-analysis.png'],
    ['/furimadeck-analytics/categories', '11-category-analysis.png'],
    ['/furimadeck-analytics/cross', '12-cross-analysis.png'],
    ['/furimadeck-analytics/advanced', '13-advanced-analysis.png'],
    ['/furimadeck-improvement', '14-sales-improvement.png'],
    ['/furimadeck-accounting?section=overview', '15-accounting-overview.png'],
    ['/furimadeck-accounting?section=purchase-info', '16-purchase-info.png'],
    ['/furimadeck-accounting?section=review', '17-accounting-review.png'],
    ['/furimadeck-accounting?section=csv', '18-accounting-csv.png'],
    ['/products/import', '19-product-csv.png'],
    ['/furimadeck-billing', '20-subscription.png'],
    ['/furimadeck-account', '21-account-settings.png'],
    ['/manual', '22-help-manual.png'],
  ];

  for (const [path, filename] of auditPages) {
    const response = await page.goto(path);
    expect(response?.status(), `${path} should load successfully`).toBe(200);
    if (path === '/furimadeck-sales') {
      await expect(page.getByRole('heading', { name: '発送・取引履歴' })).toBeVisible();
    }
    if (path === '/furimadeck-accounting?section=review') {
      await expect(page.locator('table tbody tr').filter({ hasText: 'UI監査用テスト商品' }).last()).toBeVisible();
    }
    if (path === '/furimadeck-improvement') {
      await expect(page.getByRole('heading', { name: '今日の販売改善' })).toHaveClass(/text-white/);
      await expect(page.getByText('過去の販売実績と現在の在庫をもとにした判断材料です。自動値下げや自動出品は行いません。')).toHaveClass(/text-slate-200/);
      const stagnantDays = page.locator('input[name="stagnant_days"]');
      await expect(stagnantDays).toHaveValue('30');
      await stagnantDays.fill('45');
      await page.getByRole('button', { name: '滞留商品を検索' }).click();
      await expect(page).toHaveURL(/stagnant_days=45/);
      await expect(stagnantDays).toHaveValue('45');
    }
    if (path === '/furimadeck-analytics/advanced') {
      await expect(page.getByRole('heading', { name: '高度分析' })).toHaveClass(/text-white/);
    }
    if (path === '/furimadeck-analytics/categories') {
      await expect(page.getByRole('heading', { name: 'ジャンル別分析' })).toHaveClass(/text-slate-900/);
      await expect(page.getByText(/大分類・中分類・小分類ごとに/)).toHaveClass(/text-slate-700/);
    }
    await capture(page, filename, true);
  }

  const chartPages = [
    ['/furimadeck-dashboard', ['furimadeckMonthlySalesChart']],
    ['/furimadeck-analytics', ['furimadeckAnalyticsTrendChart', 'furimadeckMarketplaceChart', 'furimadeckSalesCategoryChart']],
    ['/furimadeck-analytics/categories', [
      'furimadeckRootCategoryChart',
      'furimadeckRootCategoryQuantityChart',
      'furimadeckMiddleCategoryChart',
      'furimadeckMiddleCategoryQuantityChart',
      'furimadeckSmallCategoryChart',
      'furimadeckSmallCategoryQuantityChart',
    ]],
  ];

  for (const [path, canvasIds] of chartPages) {
    const response = await page.goto(path);
    expect(response?.status(), `${path} should load successfully`).toBe(200);

    for (const canvasId of canvasIds) {
      await page.waitForFunction((id) => window.Chart?.getChart?.(document.getElementById(id)), canvasId);
    }

    if (path === '/furimadeck-dashboard') {
      const chartColors = await page.evaluate((id) => {
        const chart = window.Chart.getChart(document.getElementById(id));

        return {
          text: chart.options.plugins.legend.labels.color,
          grid: chart.options.scales.x.grid.color,
        };
      }, 'furimadeckMonthlySalesChart');
      expect(chartColors).toEqual({ text: '#f8fafc', grid: 'rgba(226, 232, 240, 0.14)' });
    }
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto('/furimadeck-dashboard');
  const desktopDashboardBackground = await page.locator('#furimadeck-dashboard').evaluate((element) => ({
    attachments: getComputedStyle(element).backgroundAttachment.split(',').map((value) => value.trim()),
    imageIsSet: getComputedStyle(element).backgroundImage !== 'none',
  }));
  expect(desktopDashboardBackground).toEqual({ attachments: ['fixed', 'fixed'], imageIsSet: true });
  await capture(page, '26-dashboard-desktop-viewport-top.png');
  await page.evaluate(() => window.scrollTo(0, 800));
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  await capture(page, '27-dashboard-desktop-viewport-scrolled.png');
  await page.evaluate(() => window.scrollTo(0, 0));
  const desktopCollapsible = page.locator('#furimadeck-dashboard .dashboard-mobile-collapsible').first();
  await expect(desktopCollapsible).toHaveAttribute('open', '');
  await expect(desktopCollapsible.locator('summary')).toBeHidden();
  await expect(desktopCollapsible.locator('.dashboard-mobile-content')).toBeVisible();
  const dashboardTopSection = page.locator('#furimadeck-dashboard > div > section.grid').first();
  await expect(dashboardTopSection.getByRole('heading', { name: 'FurimaDeckからのお知らせ' })).toHaveCount(0);
  const dashboardNotice = page.locator('#furimadeck-dashboard > div > section.mt-8').filter({ hasText: 'FurimaDeckからのお知らせ' });
  await expect(dashboardNotice.getByRole('heading', { name: 'FurimaDeckからのお知らせ' })).toBeVisible();
  const noticeFollowsTodayWork = await page.evaluate(() => {
    const dashboard = document.querySelector('#furimadeck-dashboard > div');
    const todayWork = [...dashboard.querySelectorAll('details')].find((section) => section.querySelector('summary')?.textContent?.includes('今日やること'));
    const notice = [...dashboard.querySelectorAll('section')].find((section) => section.querySelector('h2')?.textContent?.includes('FurimaDeckからのお知らせ'));

    return Boolean(todayWork && notice && (todayWork.compareDocumentPosition(notice) & Node.DOCUMENT_POSITION_FOLLOWING));
  });
  expect(noticeFollowsTodayWork).toBe(true);
  const desktopRecentProductsCard = page.locator('#furimadeck-dashboard details.dashboard-mobile-collapsible').filter({ hasText: '最近更新した商品' });
  const cardBorderWidths = await desktopRecentProductsCard.evaluate((element) => [
    getComputedStyle(element).borderTopWidth,
    getComputedStyle(element.querySelector('.dashboard-mobile-content > article')).borderTopWidth,
  ]);
  expect(cardBorderWidths).toEqual(['1px', '0px']);
  await expect(page.locator('#furimadeck-dashboard > footer')).toHaveCount(0);
  const desktopPortfolioColumns = await page.locator('.furimadeck-dashboard-portfolio').evaluate((element) =>
    getComputedStyle(element).gridTemplateColumns.split(' ').length,
  );
  expect(desktopPortfolioColumns).toBe(5);
  const productMenu = page.locator('nav details').nth(0);
  await productMenu.locator('summary').click();
  await expect(productMenu.getByRole('link', { name: '商品一覧', exact: true })).toBeVisible();
  await page.mouse.move(1400, 900);
  await expect(productMenu).not.toHaveAttribute('open', '');
  const analyticsMenu = page.locator('nav details').nth(1);
  await analyticsMenu.locator('summary').click();
  await analyticsMenu.getByRole('link', { name: /クロス分析/ }).click();
  await expect(page).toHaveURL(/furimadeck-analytics\/cross/);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/furimadeck-dashboard');
  const mobileCollapsible = page.locator('#furimadeck-dashboard .dashboard-mobile-collapsible').first();
  await expect(mobileCollapsible).not.toHaveAttribute('open', '');
  await expect(mobileCollapsible.locator('summary')).toBeVisible();
  await expect(mobileCollapsible.locator('.dashboard-mobile-content')).toBeHidden();
  await capture(page, '23-mobile-dashboard.png', true);
  await page.getByRole('button', { name: 'メニューを開く' }).click();
  await expect(page.getByRole('link', { name: '商品一覧', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: /販売改善/ })).toBeVisible();
  await expect(page.locator('nav > div').nth(1)).toHaveClass(/bg-slate-900/);
  await capture(page, '25-mobile-menu-open.png', true);
  await page.goto('/products');
  await capture(page, '24-mobile-products.png', true);
});
