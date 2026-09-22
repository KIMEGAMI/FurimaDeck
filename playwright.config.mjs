import path from 'node:path';
import { defineConfig, devices } from 'playwright/test';

const e2eDatabase = path.resolve('storage/framework/testing/furimadeck-e2e.sqlite');

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  fullyParallel: false,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8002',
    trace: 'retain-on-failure',
    ...devices['Desktop Chrome'],
  },
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8002',
    url: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8002',
    reuseExistingServer: true,
    timeout: 120_000,
    env: {
      ...process.env,
      APP_URL: 'http://127.0.0.1:8002',
      DB_CONNECTION: 'furimadeck',
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
