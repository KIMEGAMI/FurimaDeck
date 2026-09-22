import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const databasePath = resolve('storage/framework/testing/furimadeck-e2e.sqlite');
mkdirSync(dirname(databasePath), { recursive: true });
rmSync(databasePath, { force: true });
writeFileSync(databasePath, '');

const environment = {
  ...process.env,
  DB_CONNECTION: 'furimadeck',
  FURIMADECK_DB_DRIVER: 'sqlite',
  FURIMADECK_DB_DATABASE: databasePath,
};

const run = (args) => {
  const result = spawnSync('php', args, { env: environment, stdio: 'inherit', shell: false });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
};

run(['artisan', 'migrate', '--database=furimadeck', '--path=database/migrations/furimadeck', '--force']);
run(['artisan', 'db:seed', '--database=furimadeck', '--class=FurimaDeckMarketplaceSeeder', '--no-interaction']);
run(['artisan', 'furimadeck:sync-demo-user']);
