import path from 'node:path';
import { defineConfig, devices } from 'playwright/test';
import { furimadeckE2eEnvironment, resolveFurimaDeckE2eServer } from './scripts/furimadeck-e2e-runtime.mjs';

const e2eDatabaseSuffix = process.env.FURIMADECK_E2E_DB_SUFFIX;
if (!e2eDatabaseSuffix || !/^[a-z0-9-]{1,64}$/i.test(e2eDatabaseSuffix)) {
  throw new Error('FURIMADECK_E2E_DB_SUFFIX must contain only letters, numbers, or hyphens.');
}
const e2eDatabaseName = `furimadeck-e2e-${e2eDatabaseSuffix}.sqlite`;
const e2eDatabase = path.resolve('storage/framework/testing', e2eDatabaseName);
const e2eStorage = path.resolve('storage/framework/testing', `furimadeck-e2e-storage-${e2eDatabaseSuffix}`);
const e2eTestingRoot = path.resolve('storage/framework/testing');
const { port: e2ePort, baseUrl: e2eBaseUrl } = resolveFurimaDeckE2eServer();
const browserChannel = process.env.FURIMADECK_E2E_BROWSER_CHANNEL;

if (browserChannel && browserChannel !== 'chrome') {
  throw new Error('FURIMADECK_E2E_BROWSER_CHANNEL supports only the installed Chrome channel when explicitly set.');
}

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: e2eBaseUrl,
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
    ...(browserChannel ? { channel: browserChannel } : {}),
  },
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${e2ePort}`,
    url: e2eBaseUrl,
    reuseExistingServer: false,
    timeout: 120_000,
    env: {
      ...furimadeckE2eEnvironment(process.env, e2eTestingRoot),
      APP_ENV: 'testing',
      APP_DEBUG: 'false',
      APP_URL: e2eBaseUrl,
      FURIMADECK_CUTOVER_ENABLED: 'true',
      FURIMADECK_PRODUCT_MANAGEMENT_ENABLED: 'true',
      DB_CONNECTION: 'furimadeck',
      SESSION_DRIVER: 'file',
      LARAVEL_STORAGE_PATH: e2eStorage,
      FURIMADECK_DB_URL: '',
      FURIMADECK_DEMO_USER_ENABLED: process.env.FURIMADECK_DEMO_USER_ENABLED,
      FURIMADECK_DEMO_USER_EMAIL: process.env.FURIMADECK_DEMO_USER_EMAIL,
      FURIMADECK_DEMO_USER_PASSWORD: process.env.FURIMADECK_DEMO_USER_PASSWORD,
      MAIL_MAILER: 'log',
      FURIMADECK_DB_DRIVER: 'sqlite',
      FURIMADECK_DB_DATABASE: e2eDatabase,
      FURIMADECK_ACCOUNTING_SETTLEMENT_ACCOUNT: '売掛金',
      FURIMADECK_ACCOUNTING_SALES_ACCOUNT: '売上高',
      FURIMADECK_ACCOUNTING_FEES_ACCOUNT: '販売手数料',
      FURIMADECK_ACCOUNTING_COST_ACCOUNT: '仕入高',
      FURIMADECK_ACCOUNTING_INVENTORY_ACCOUNT: '商品',
    },
  },
});
