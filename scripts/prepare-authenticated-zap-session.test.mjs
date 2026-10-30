import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { prepareAuthenticatedZapSession } from './prepare-authenticated-zap-session.mjs';

test('authenticates a disposable CI account, verifies a protected route, and writes only the session cookie', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'furimadeck-zap-auth-'));
  const cookieFile = join(directory, 'session-cookie.txt');
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (calls.length === 1) {
      return new Response('<form><input type="hidden" name="_token" value="csrf-token"></form>', {
        status: 200,
        headers: [
          ['set-cookie', 'app_session=anonymous; Path=/; Secure; HttpOnly'],
          ['set-cookie', 'XSRF-TOKEN=csrf-cookie; Path=/; Secure; SameSite=Lax'],
        ],
      });
    }
    if (calls.length === 2) {
      assert.equal(calls[1].options.headers.cookie, 'app_session=anonymous; XSRF-TOKEN=csrf-cookie');
      assert.equal(new URLSearchParams(calls[1].options.body).get('_token'), 'csrf-token');
      assert.equal(new URLSearchParams(calls[1].options.body).get('email'), 'zap-ci@example.invalid');
      assert.equal(new URLSearchParams(calls[1].options.body).get('password'), 'disposable-password');
      return new Response('', {
        status: 302,
        headers: {
          location: '/furimadeck-dashboard',
          'set-cookie': 'app_session=authenticated; Path=/; Secure; HttpOnly',
        },
      });
    }
    assert.equal(calls[2].url, 'https://127.0.0.1:8443/furimadeck-dashboard');
    assert.equal(calls[2].options.headers.cookie, 'app_session=authenticated; XSRF-TOKEN=csrf-cookie');
    return new Response('<main>private dashboard</main>', { status: 200 });
  };

  try {
    const result = await prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email: 'zap-ci@example.invalid',
      password: 'disposable-password',
      cookieFile,
      fetchImpl,
    });

    assert.deepEqual(result, { loginStatus: 302, protectedStatus: 200 });
    assert.equal(await readFile(cookieFile, 'utf8'), 'app_session=authenticated');
    assert.equal(calls.length, 3);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('rejects a non-loopback target before sending credentials', async () => {
  let requestCount = 0;
  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://example.com',
      email: 'zap-ci@example.invalid',
      password: 'disposable-password',
      cookieFile: join(tmpdir(), 'unused-zap-cookie.txt'),
      fetchImpl: async () => {
        requestCount += 1;
        return new Response('', { status: 200 });
      },
    }),
    /HTTPS loopback/,
  );
  assert.equal(requestCount, 0);
});

test('fails closed when the login page does not include a CSRF token', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'furimadeck-zap-no-csrf-'));
  let requestCount = 0;
  try {
    await assert.rejects(
      prepareAuthenticatedZapSession({
        appUrl: 'https://127.0.0.1:8443',
        email: 'zap-ci@example.invalid',
        password: 'disposable-password',
        cookieFile: join(directory, 'session-cookie.txt'),
        fetchImpl: async () => {
          requestCount += 1;
          return new Response('<form><input name="email"></form>', { status: 200 });
        },
      }),
      /CSRF form token/,
    );
    assert.equal(requestCount, 1);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('does not persist a session when authentication cannot reach the protected dashboard', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'furimadeck-zap-auth-denied-'));
  const cookieFile = join(directory, 'session-cookie.txt');
  let requestCount = 0;
  const fetchImpl = async () => {
    requestCount += 1;
    if (requestCount === 1) {
      return new Response('<form><input name="_token" value="csrf-token"></form>', {
        status: 200,
        headers: { 'set-cookie': 'app_session=anonymous; Path=/; Secure; HttpOnly' },
      });
    }
    if (requestCount === 2) {
      return new Response('', { status: 302, headers: { location: '/furimadeck-dashboard' } });
    }
    return new Response('', { status: 302, headers: { location: '/login' } });
  };

  try {
    await assert.rejects(
      prepareAuthenticatedZapSession({
        appUrl: 'https://127.0.0.1:8443',
        email: 'zap-ci@example.invalid',
        password: 'disposable-password',
        cookieFile,
        fetchImpl,
      }),
      /could not reach the protected dashboard/,
    );
    await assert.rejects(readFile(cookieFile), { code: 'ENOENT' });
    assert.equal(requestCount, 3);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
