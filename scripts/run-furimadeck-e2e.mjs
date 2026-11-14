import { randomBytes, randomUUID } from 'node:crypto';
import { lstatSync, mkdirSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { assertFurimaDeckE2ePortAvailable, furimadeckE2eEnvironment, resolveFurimaDeckE2eServer } from './furimadeck-e2e-runtime.mjs';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const testingRoot = resolve(projectRoot, 'storage/framework/testing');
mkdirSync(testingRoot, { recursive: true });
const isolatedDatabaseProbeUrl = 'mysql://e2e-probe@127.0.0.1:1/should_not_connect';
const playwrightArguments = process.argv.slice(2);
if (!playwrightArguments.includes('--list')) {
  const { port } = resolveFurimaDeckE2eServer();
  await assertFurimaDeckE2ePortAvailable(port);
  console.log(`E2E loopback port ${port} is available.`);
}

const databaseSuffix = process.env.FURIMADECK_E2E_DB_SUFFIX || randomUUID();
if (!/^[a-z0-9-]{1,64}$/i.test(databaseSuffix)) {
  throw new Error('FURIMADECK_E2E_DB_SUFFIX must contain only letters, numbers, or hyphens.');
}

const e2eServer = resolveFurimaDeckE2eServer();
const testSecretBytes = 32;
const environment = {
  ...furimadeckE2eEnvironment(process.env, testingRoot),
  APP_KEY: `base64:${randomBytes(testSecretBytes).toString('base64')}`,
  APP_URL: e2eServer.baseUrl,
  FURIMADECK_DEMO_USER_ENABLED: 'true',
  FURIMADECK_DEMO_USER_EMAIL: `furimadeck-e2e-${databaseSuffix}@example.test`,
  FURIMADECK_DEMO_USER_PASSWORD: randomBytes(testSecretBytes).toString('base64url'),
  FURIMADECK_E2E_DB_SUFFIX: databaseSuffix,
  FURIMADECK_DB_URL: isolatedDatabaseProbeUrl,
};
const generatedArtifacts = [
  {
    path: resolve(testingRoot, `furimadeck-e2e-${databaseSuffix}.sqlite`),
    directory: false,
  },
  {
    path: resolve(testingRoot, `furimadeck-e2e-storage-${databaseSuffix}`),
    directory: true,
  },
];

const runNode = (args) => {
  const result = spawnSync(process.execPath, args, {
    cwd: projectRoot,
    env: environment,
    stdio: 'inherit',
    shell: false,
  });
  if (result.error) throw result.error;
  return result.status ?? 1;
};

console.log(`Using isolated FurimaDeck E2E data suffix: ${databaseSuffix}`);

const cleanupGeneratedArtifacts = () => {
  for (const artifact of generatedArtifacts) {
    if (dirname(artifact.path) !== testingRoot) {
      throw new Error('Refusing to remove an E2E artifact outside the isolated testing directory.');
    }

    let stats;
    try {
      stats = lstatSync(artifact.path);
    } catch (error) {
      if (error.code === 'ENOENT') continue;
      throw error;
    }

    if (stats.isSymbolicLink() || (artifact.directory ? !stats.isDirectory() : !stats.isFile())) {
      throw new Error(`Refusing to remove an unexpected E2E artifact: ${artifact.path}`);
    }

    rmSync(artifact.path, { force: true, recursive: artifact.directory });
  }

  console.log('Removed the isolated E2E database and storage directory after completion.');
};

let exitStatus = 1;

try {
  const prepareStatus = runNode([resolve(projectRoot, 'scripts/prepare-furimadeck-e2e-db.mjs')]);
  if (prepareStatus !== 0) {
    exitStatus = prepareStatus;
  } else {
    exitStatus = runNode([
      resolve(projectRoot, 'node_modules/@playwright/test/cli.js'),
      'test',
      ...playwrightArguments,
    ]);
  }
} finally {
  cleanupGeneratedArtifacts();
}

process.exit(exitStatus);
