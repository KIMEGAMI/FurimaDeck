import assert from 'node:assert/strict';
import test from 'node:test';
import { prepareAuthenticatedZapSession } from './prepare-authenticated-zap-session.mjs';

const email = 'furimadeck-zap-12345.2@example.invalid';
const password = 'a'.repeat(64);

function authenticatedFetch({
  rotateSession = true,
  protectedStatus = 200,
  protectedBody = '<h1 class="sr-only">FurimaDeck Dashboard</h1>',
  secureCookie = true,
  preAuthStatus = 302,
  preAuthLocation = '/login',
} = {}) {
  const calls = [];
  const fetchImpl = async (url, options) => {
    calls.push({ url: String(url), options });
    if (calls.length === 1) {
      assert.equal(calls[0].url, 'https://127.0.0.1:8443/furimadeck-dashboard');
      assert.equal(calls[0].options.headers.cookie, undefined);
      return new Response('', {
        status: preAuthStatus,
        headers: preAuthLocation ? { location: preAuthLocation } : {},
      });
    }
    if (calls.length === 2) {
      assert.equal(calls[1].url, 'https://127.0.0.1:8443/login');
      return new Response('<form><input type="hidden" name="_token" value="csrf-token"></form>', {
        status: 200,
        headers: [
          ['set-cookie', `app_session=anonymous; Path=/; ${secureCookie ? 'Secure; ' : ''}HttpOnly; SameSite=Lax`],
          ['set-cookie', 'XSRF-TOKEN=csrf-cookie; Path=/; Secure; HttpOnly; SameSite=Lax'],
        ],
      });
    }
    if (calls.length === 3) {
      assert.equal(calls[2].options.headers.cookie, 'app_session=anonymous; XSRF-TOKEN=csrf-cookie');
      assert.equal(new URLSearchParams(calls[2].options.body).get('_token'), 'csrf-token');
      assert.equal(new URLSearchParams(calls[2].options.body).get('email'), email);
      assert.equal(new URLSearchParams(calls[2].options.body).get('password'), password);
      return new Response('', {
        status: 302,
        headers: {
          location: '/furimadeck-dashboard',
          'set-cookie': `app_session=${rotateSession ? 'authenticated' : 'anonymous'}; Path=/; Secure; HttpOnly; SameSite=Lax`,
        },
      });
    }
    assert.equal(calls[3].url, 'https://127.0.0.1:8443/furimadeck-dashboard');
    assert.equal(calls[3].options.headers.cookie, `app_session=${rotateSession ? 'authenticated' : 'anonymous'}; XSRF-TOKEN=csrf-cookie`);
    return new Response(protectedBody, { status: protectedStatus });
  };
  return { calls, fetchImpl };
}

test('authenticates a disposable CI account, rotates its session, and verifies a protected route', async () => {
  const { calls, fetchImpl } = authenticatedFetch();

  const result = await prepareAuthenticatedZapSession({
    appUrl: 'https://127.0.0.1:8443',
    email,
    password,
    sessionCookieName: 'app_session',
    fetchImpl,
  });

  assert.deepEqual(result, { loginStatus: 302, protectedStatus: 200, sessionRotated: true });
  assert.equal(calls.length, 4);
});

test('fails if the protected dashboard accepts an unauthenticated request', async () => {
  const { calls, fetchImpl } = authenticatedFetch({ preAuthStatus: 200, preAuthLocation: null });

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /did not reject an unauthenticated request/,
  );
  assert.equal(calls.length, 1);
});

test('rejects an unauthenticated redirect to a different origin before submitting credentials', async () => {
  const { calls, fetchImpl } = authenticatedFetch({
    preAuthLocation: 'https://127.0.0.1:9443/login',
  });

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /did not redirect unauthenticated access to login/,
  );
  assert.equal(calls.length, 1);
});

test('rejects a credential outside the generated disposable identity pattern before any request', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email: 'real-person@example.com',
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async () => {
        requestCount += 1;
        return new Response('', { status: 200 });
      },
    }),
    /generated disposable CI account/,
  );
  assert.equal(requestCount, 0);
});

test('rejects a non-loopback target before sending credentials', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://example.com',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async () => {
        requestCount += 1;
        return new Response('', { status: 200 });
      },
    }),
    /HTTPS loopback/,
  );
  assert.equal(requestCount, 0);
});

test('rejects an initial session cookie missing required security attributes before submitting credentials', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async () => {
        requestCount += 1;
        if (requestCount === 1) {
          return new Response('', { status: 302, headers: { location: '/login' } });
        }
        return new Response('<form><input name="_token" value="csrf-token"></form>', {
          status: 200,
          headers: { 'set-cookie': 'app_session=anonymous; Path=/' },
        });
      },
    }),
    /missing required security attributes/,
  );
  assert.equal(requestCount, 2);
});

test('rejects a session cookie using SameSite=None before submitting credentials', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async (url) => {
        requestCount += 1;
        if (String(url).endsWith('/furimadeck-dashboard')) {
          return new Response('', { status: 302, headers: { location: '/login' } });
        }
        return new Response('<form><input name="_token" value="csrf-token"></form>', {
          status: 200,
          headers: { 'set-cookie': 'app_session=anonymous; Path=/; Secure; HttpOnly; SameSite=None' },
        });
      },
    }),
    /missing required security attributes/,
  );
  assert.equal(requestCount, 2);
});

test('rejects duplicate SameSite attributes before submitting credentials', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async (url) => {
        requestCount += 1;
        if (String(url).endsWith('/furimadeck-dashboard')) {
          return new Response('', { status: 302, headers: { location: '/login' } });
        }
        return new Response('<form><input name="_token" value="csrf-token"></form>', {
          status: 200,
          headers: {
            'set-cookie': 'app_session=anonymous; Path=/; Secure; HttpOnly; SameSite=Lax; SameSite=None',
          },
        });
      },
    }),
    /missing required security attributes/,
  );
  assert.equal(requestCount, 2);
});

test('rejects a session cookie with a non-root path or an explicit Domain', async () => {
  for (const cookie of [
    'app_session=anonymous; Path=/login; Secure; HttpOnly; SameSite=Lax',
    'app_session=anonymous; Path=/; Domain=127.0.0.1; Secure; HttpOnly; SameSite=Lax',
  ]) {
    let requestCount = 0;
    await assert.rejects(
      prepareAuthenticatedZapSession({
        appUrl: 'https://127.0.0.1:8443',
        email,
        password,
        sessionCookieName: 'app_session',
        fetchImpl: async (url) => {
          requestCount += 1;
          if (String(url).endsWith('/furimadeck-dashboard')) {
            return new Response('', { status: 302, headers: { location: '/login' } });
          }
          return new Response('<form><input name="_token" value="csrf-token"></form>', {
            status: 200,
            headers: { 'set-cookie': cookie },
          });
        },
      }),
      /missing required security attributes/,
    );
    assert.equal(requestCount, 2);
  }
});

test('fails closed when the login page does not include a CSRF token', async () => {
  let requestCount = 0;

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: async () => {
        requestCount += 1;
        if (requestCount === 1) {
          return new Response('', { status: 302, headers: { location: '/login' } });
        }
        return new Response('<form><input name="email"></form>', {
          status: 200,
          headers: { 'set-cookie': 'app_session=anonymous; Path=/; Secure; HttpOnly; SameSite=Lax' },
        });
      },
    }),
    /CSRF form token/,
  );
  assert.equal(requestCount, 2);
});

test('rejects a login redirect that does not go to the protected dashboard', async () => {
  const { fetchImpl } = authenticatedFetch();
  const redirectingFetch = async (url, options) => {
    if (options.method === 'POST') {
      return new Response('', {
        status: 302,
        headers: {
          location: '/login',
          'set-cookie': 'app_session=authenticated; Path=/; Secure; HttpOnly',
        },
      });
    }
    return fetchImpl(url, options);
  };

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl: redirectingFetch,
    }),
    /did not reach an authenticated destination/,
  );
});

test('fails if login does not rotate the session identifier', async () => {
  const { fetchImpl } = authenticatedFetch({ rotateSession: false });

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /did not rotate the session identifier/,
  );
});

test('fails if the post-login session cookie loses required security attributes', async () => {
  let requestCount = 0;
  const fetchImpl = async (url, options) => {
    requestCount += 1;
    if (requestCount === 1) {
      return new Response('', { status: 302, headers: { location: '/login' } });
    }
    if (requestCount === 2) {
      return new Response('<input name="_token" value="csrf-token">', {
        status: 200,
        headers: { 'set-cookie': 'app_session=anonymous; Path=/; Secure; HttpOnly; SameSite=Lax' },
      });
    }
    return new Response('', {
      status: 302,
      headers: {
        location: '/furimadeck-dashboard',
        'set-cookie': 'app_session=authenticated; Path=/; HttpOnly; SameSite=Lax',
      },
    });
  };

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /authenticated session cookie is missing required security attributes/,
  );
  assert.equal(requestCount, 3);
});

test('fails if the authenticated disposable account cannot access the protected dashboard', async () => {
  const { fetchImpl } = authenticatedFetch({ protectedStatus: 302 });

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /could not reach the protected dashboard/,
  );
});

test('fails if the protected route returns HTTP 200 with a login or generic page instead of the dashboard', async () => {
  const { fetchImpl } = authenticatedFetch({
    protectedBody: '<title>ログイン | FurimaDeck</title><form>login</form>',
  });

  await assert.rejects(
    prepareAuthenticatedZapSession({
      appUrl: 'https://127.0.0.1:8443',
      email,
      password,
      sessionCookieName: 'app_session',
      fetchImpl,
    }),
    /did not return its authenticated page marker/,
  );
});
