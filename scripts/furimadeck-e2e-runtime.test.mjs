import assert from 'node:assert/strict';
import test from 'node:test';
import { furimadeckE2eEnvironment, resolveFurimaDeckE2eServer } from './furimadeck-e2e-runtime.mjs';

test('forces local filesystem disks even when external R2 disks are configured', () => {
  const environment = furimadeckE2eEnvironment({
    FILESYSTEM_DISK: 's3',
    FURIMADECK_PRODUCT_IMAGE_DISK: 'furimadeck_r2',
    FURIMADECK_R2_BUCKET: 'production-bucket',
  });

  assert.deepEqual({
    BROADCAST_CONNECTION: environment.BROADCAST_CONNECTION,
    CACHE_STORE: environment.CACHE_STORE,
    FILESYSTEM_DISK: environment.FILESYSTEM_DISK,
    FURIMADECK_PRODUCT_IMAGE_DISK: environment.FURIMADECK_PRODUCT_IMAGE_DISK,
    FURIMADECK_R2_BUCKET: environment.FURIMADECK_R2_BUCKET,
    LOG_CHANNEL: environment.LOG_CHANNEL,
    MAIL_MAILER: environment.MAIL_MAILER,
    QUEUE_CONNECTION: environment.QUEUE_CONNECTION,
  }, {
    BROADCAST_CONNECTION: 'null',
    CACHE_STORE: 'array',
    FILESYSTEM_DISK: 'local',
    FURIMADECK_PRODUCT_IMAGE_DISK: 'local',
    FURIMADECK_R2_BUCKET: 'production-bucket',
    LOG_CHANNEL: 'stderr',
    MAIL_MAILER: 'log',
    QUEUE_CONNECTION: 'sync',
  });
});

test('removes external provider credentials from the E2E child environment', () => {
  const environment = furimadeckE2eEnvironment({
    AWS_SECRET_ACCESS_KEY: 'configured',
    FURIMADECK_FREEE_CLIENT_SECRET: 'configured',
    FURIMADECK_MF_CLIENT_SECRET: 'configured',
    FURIMADECK_STRIPE_SECRET: 'configured',
    GOOGLE_CLIENT_SECRET: 'configured',
    LOG_SLACK_WEBHOOK_URL: 'configured',
    MAIL_PASSWORD: 'configured',
    PAPERTRAIL_URL: 'configured',
    POSTMARK_API_KEY: 'configured',
    RESEND_API_KEY: 'configured',
    SLACK_BOT_USER_OAUTH_TOKEN: 'configured',
    E2E_PORT: '8123',
  });

  for (const key of [
    'AWS_SECRET_ACCESS_KEY',
    'FURIMADECK_FREEE_CLIENT_SECRET',
    'FURIMADECK_MF_CLIENT_SECRET',
    'FURIMADECK_STRIPE_SECRET',
    'GOOGLE_CLIENT_SECRET',
    'LOG_SLACK_WEBHOOK_URL',
    'MAIL_PASSWORD',
    'PAPERTRAIL_URL',
    'POSTMARK_API_KEY',
    'RESEND_API_KEY',
    'SLACK_BOT_USER_OAUTH_TOKEN',
  ]) {
    assert.equal(environment[key], '', `${key} must not enter the E2E process`);
  }

  assert.equal(environment.E2E_PORT, '8123');
});

test('uses the isolated loopback default when no endpoint is configured', () => {
  assert.deepEqual(resolveFurimaDeckE2eServer({}), {
    port: 8002,
    baseUrl: 'http://127.0.0.1:8002',
  });
});

test('uses a configured loopback port and canonical base URL', () => {
  assert.deepEqual(resolveFurimaDeckE2eServer({ E2E_PORT: '8123' }), {
    port: 8123,
    baseUrl: 'http://127.0.0.1:8123',
  });
  assert.deepEqual(resolveFurimaDeckE2eServer({ E2E_BASE_URL: 'http://localhost:8124/' }), {
    port: 8124,
    baseUrl: 'http://127.0.0.1:8124',
  });
});

test('rejects non-loopback URLs, credentials, and URL suffixes', () => {
  const invalidUrls = [
    'https://127.0.0.1:8002',
    'http://example.com:8002',
    'http://127.0.0.2:8002',
    'http://user:secret@127.0.0.1:8002',
    'http://127.0.0.1:8002/app',
    'http://127.0.0.1:8002/?debug=1',
    'http://127.0.0.1:8002/#fragment',
    'not a URL',
  ];

  for (const E2E_BASE_URL of invalidUrls) {
    assert.throws(
      () => resolveFurimaDeckE2eServer({ E2E_BASE_URL }),
      /E2E_BASE_URL must/,
      E2E_BASE_URL,
    );
  }
});

test('rejects mismatched URL and port configuration', () => {
  assert.throws(
    () => resolveFurimaDeckE2eServer({ E2E_BASE_URL: 'http://127.0.0.1:8003', E2E_PORT: '8004' }),
    /E2E_PORT must match/,
  );
});

test('rejects privileged, out-of-range, and non-integer ports', () => {
  for (const E2E_PORT of ['0', '1023', '65536', '8002.5', 'invalid']) {
    assert.throws(
      () => resolveFurimaDeckE2eServer({ E2E_PORT }),
      /E2E_PORT must be an unprivileged TCP port/,
      E2E_PORT,
    );
  }
});
