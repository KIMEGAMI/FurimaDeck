import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const databaseSuffix = process.env.FURIMADECK_E2E_DB_SUFFIX;
if (!databaseSuffix || !/^[a-z0-9-]{1,64}$/i.test(databaseSuffix)) {
  throw new Error('FURIMADECK_E2E_DB_SUFFIX must contain only letters, numbers, or hyphens.');
}
const databaseName = `furimadeck-e2e-${databaseSuffix}.sqlite`;
const databasePath = resolve('storage/framework/testing', databaseName);
const storagePath = resolve('storage/framework/testing', `furimadeck-e2e-storage-${databaseSuffix}`);
mkdirSync(dirname(databasePath), { recursive: true });
if (existsSync(databasePath) || existsSync(storagePath)) {
  throw new Error('Refusing to reuse an existing FurimaDeck E2E database or storage directory. Start a new run for a fresh isolated path.');
}
mkdirSync(storagePath);
writeFileSync(databasePath, '', { flag: 'wx' });
for (const directory of ['framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/private', 'app/public']) {
  mkdirSync(resolve(storagePath, directory), { recursive: true });
}

const environment = {
  ...process.env,
  DB_CONNECTION: 'furimadeck',
  FURIMADECK_DB_DRIVER: 'sqlite',
  FURIMADECK_DB_DATABASE: databasePath,
  FURIMADECK_DB_URL: '',
  SESSION_DRIVER: 'file',
  LARAVEL_STORAGE_PATH: storagePath,
};

const run = (args) => {
  const result = spawnSync('php', args, { env: environment, stdio: 'inherit', shell: false });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
};

run(['artisan', 'migrate', '--database=furimadeck', '--path=database/migrations/furimadeck', '--force']);
run(['artisan', 'db:seed', '--database=furimadeck', '--class=FurimaDeckMarketplaceSeeder', '--no-interaction']);
run(['artisan', 'furimadeck:sync-demo-user', '--sync-password']);
