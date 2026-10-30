import { randomBytes, randomUUID } from 'node:crypto';
import { createServer } from 'node:net';

const DEFAULT_E2E_PORT = 8002;
const MIN_UNPRIVILEGED_PORT = 1024;
const MAX_TCP_PORT = 65535;
const EXTERNAL_CREDENTIAL_ENV_VARS = [
  'AWS_ACCESS_KEY_ID',
  'AWS_SECRET_ACCESS_KEY',
  'FURIMADECK_FREEE_CLIENT_ID',
  'FURIMADECK_FREEE_CLIENT_SECRET',
  'FURIMADECK_MF_CLIENT_ID',
  'FURIMADECK_MF_CLIENT_SECRET',
  'FURIMADECK_R2_ACCESS_KEY_ID',
  'FURIMADECK_R2_SECRET_ACCESS_KEY',
  'FURIMADECK_STRIPE_SECRET',
  'FURIMADECK_STRIPE_WEBHOOK_SECRET',
  'GOOGLE_CLIENT_ID',
  'GOOGLE_CLIENT_SECRET',
  'LOG_SLACK_WEBHOOK_URL',
  'MAIL_PASSWORD',
  'MAIL_URL',
  'MAIL_USERNAME',
  'PAPERTRAIL_URL',
  'POSTMARK_API_KEY',
  'PRODUCT_IMAGES_ACCESS_KEY_ID',
  'PRODUCT_IMAGES_SECRET_ACCESS_KEY',
  'RESEND_API_KEY',
  'SLACK_BOT_USER_OAUTH_TOKEN',
];

export function furimadeckE2eEnvironment(environment = process.env) {
  const e2eAppKey = environment.FURIMADECK_E2E_APP_KEY || `base64:${randomBytes(32).toString('base64')}`;
  const e2eDemoUserPassword = environment.FURIMADECK_E2E_DEMO_USER_PASSWORD || randomUUID();
  const disabledCredentials = Object.fromEntries(
    EXTERNAL_CREDENTIAL_ENV_VARS.map((name) => [name, '']),
  );

  return {
    ...environment,
    ...disabledCredentials,
    APP_ENV: 'testing',
    APP_KEY: e2eAppKey,
    FURIMADECK_E2E_APP_KEY: e2eAppKey,
    BROADCAST_CONNECTION: 'null',
    CACHE_STORE: 'array',
    FILESYSTEM_DISK: 'local',
    FURIMADECK_CUTOVER_ENABLED: 'true',
    FURIMADECK_PRODUCT_MANAGEMENT_ENABLED: 'true',
    FURIMADECK_PRODUCT_IMAGE_DISK: 'local',
    FURIMADECK_DEMO_USER_ENABLED: 'true',
    FURIMADECK_DEMO_USER_EMAIL: 'furimadeck-e2e@example.test',
    FURIMADECK_DEMO_USER_PASSWORD: e2eDemoUserPassword,
    FURIMADECK_E2E_DEMO_USER_PASSWORD: e2eDemoUserPassword,
    LOG_CHANNEL: 'stderr',
    MAIL_MAILER: 'log',
    QUEUE_CONNECTION: 'sync',
  };
}

export function resolveFurimaDeckE2eServer(environment = process.env) {
  const configuredBaseUrl = environment.E2E_BASE_URL;
  let configuredUrl;
  if (configuredBaseUrl) {
    try {
      configuredUrl = new URL(configuredBaseUrl);
    } catch {
      throw new Error('E2E_BASE_URL must be a valid loopback HTTP URL.');
    }
    if (configuredUrl.protocol !== 'http:'
      || !['127.0.0.1', 'localhost'].includes(configuredUrl.hostname)
      || configuredUrl.username
      || configuredUrl.password
      || configuredUrl.pathname !== '/'
      || configuredUrl.search
      || configuredUrl.hash) {
      throw new Error('E2E_BASE_URL must target only the local loopback server without credentials, path, query, or fragment.');
    }
  }

  const configuredPort = environment.E2E_PORT;
  if (configuredPort && configuredUrl?.port && Number(configuredPort) !== Number(configuredUrl.port)) {
    throw new Error('E2E_PORT must match the port in E2E_BASE_URL.');
  }
  const port = Number(configuredPort ?? configuredUrl?.port ?? DEFAULT_E2E_PORT);
  if (!Number.isInteger(port) || port < MIN_UNPRIVILEGED_PORT || port > MAX_TCP_PORT) {
    throw new Error(`E2E_PORT must be an unprivileged TCP port (${MIN_UNPRIVILEGED_PORT}-${MAX_TCP_PORT}).`);
  }

  return { port, baseUrl: `http://127.0.0.1:${port}` };
}

export async function assertFurimaDeckE2ePortAvailable(port) {
  const server = createServer();
  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(port, '127.0.0.1', resolve);
    });
  } catch (error) {
    if (error.code === 'EADDRINUSE') {
      throw new Error(`E2E port ${port} is already in use. Set E2E_PORT to a free loopback port and retry.`);
    }
    if (error.code === 'EACCES' || error.code === 'EADDRNOTAVAIL') {
      throw new Error(`Cannot listen on E2E loopback port ${port} in this environment. Retry with local loopback access enabled.`);
    }
    throw error;
  } finally {
    if (server.listening) {
      await new Promise((resolve, reject) => {
        server.close((error) => error ? reject(error) : resolve());
      });
    }
  }
}
