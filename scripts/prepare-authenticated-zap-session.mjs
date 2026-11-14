import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const authenticatedDashboardMarker = '<h1 class="sr-only">FurimaDeck Dashboard</h1>';

function readAttribute(tag, name) {
  for (const match of tag.matchAll(/\s([A-Za-z_:][A-Za-z0-9_:.-]*)\s*=\s*(["'])(.*?)\2/gi)) {
    if (match[1].toLowerCase() === name.toLowerCase()) return match[3];
  }

  return null;
}

function collectCookies(response, cookies) {
  const setCookies = typeof response.headers.getSetCookie === 'function'
    ? response.headers.getSetCookie()
    : [];

  for (const setCookie of setCookies) {
    const [pair, ...attributes] = setCookie.split(';');
    const separator = pair.indexOf('=');
    if (separator < 1) continue;

    const name = pair.slice(0, separator).trim();
    const value = pair.slice(separator + 1).trim();
    const expiresImmediately = attributes.some((attribute) => /^\s*max-age\s*=\s*0\s*$/i.test(attribute));
    const sameSiteAttributes = attributes.filter((attribute) => /^\s*samesite\s*=/i.test(attribute));
    const sameSite = sameSiteAttributes.length === 1
      ? /^\s*samesite\s*=\s*(strict|lax|none)\s*$/i.exec(sameSiteAttributes[0])?.[1]?.toLowerCase() ?? null
      : null;
    const pathAttributes = attributes.filter((attribute) => /^\s*path\s*=/i.test(attribute));
    const path = pathAttributes.length === 1
      ? /^\s*path\s*=\s*(.*?)\s*$/i.exec(pathAttributes[0])?.[1] ?? null
      : null;
    const domainAttributes = attributes.filter((attribute) => /^\s*domain\s*=/i.test(attribute));
    const hostOnly = domainAttributes.length === 0;
    if (expiresImmediately) {
      cookies.delete(name);
    } else {
      cookies.set(name, {
        value,
        httpOnly: attributes.some((attribute) => /^\s*httponly\s*$/i.test(attribute)),
        secure: attributes.some((attribute) => /^\s*secure\s*$/i.test(attribute)),
        sameSite,
        path,
        hostOnly,
      });
    }
  }
}

function serializeCookies(cookies) {
  return [...cookies].map(([name, cookie]) => `${name}=${cookie.value}`).join('; ');
}

function requireLoopbackHttps(appUrl) {
  const url = new URL(appUrl);
  if (url.protocol !== 'https:' || !['127.0.0.1', 'localhost'].includes(url.hostname) || url.username || url.password) {
    throw new Error('The authenticated DAST helper only accepts an HTTPS loopback application URL.');
  }

  return url;
}

export async function prepareAuthenticatedZapSession({
  appUrl,
  email,
  password,
  sessionCookieName,
  fetchImpl = fetch,
}) {
  if (
    typeof email !== 'string'
    || !/^furimadeck-zap-[0-9]+\.[0-9]+@example\.invalid$/.test(email)
    || typeof password !== 'string'
    || !/^[0-9a-f]{64}$/.test(password)
  ) {
    throw new Error('A generated disposable CI account is required.');
  }
  if (typeof sessionCookieName !== 'string' || !/^[A-Za-z0-9_-]+$/.test(sessionCookieName)) {
    throw new Error('The configured session cookie name is invalid.');
  }

  const app = requireLoopbackHttps(appUrl);
  const cookies = new Map();
  const cookieHeader = () => serializeCookies(cookies);
  const request = async (url, options = {}) => fetchImpl(url, {
    ...options,
    headers: {
      accept: 'text/html',
      ...(cookieHeader() ? { cookie: cookieHeader() } : {}),
      ...options.headers,
    },
    redirect: 'manual',
    signal: AbortSignal.timeout(15000),
  });

  const unauthenticatedPage = await request(new URL('/furimadeck-dashboard', app.origin));
  const loginLocation = unauthenticatedPage.headers.get('location');
  if (![302, 303].includes(unauthenticatedPage.status) || !loginLocation) {
    throw new Error('The protected dashboard did not reject an unauthenticated request.');
  }
  const loginRedirect = new URL(loginLocation, app.origin);
  if (loginRedirect.origin !== app.origin || loginRedirect.pathname !== '/login') {
    throw new Error('The protected dashboard did not redirect unauthenticated access to login.');
  }

  const loginPage = await request(new URL('/login', app.origin));
  collectCookies(loginPage, cookies);
  if (loginPage.status !== 200) throw new Error('The isolated login page did not return HTTP 200.');

  const html = await loginPage.text();
  const tokenInput = [...html.matchAll(/<input\b[^>]*>/gi)]
    .map(([tag]) => tag)
    .find((tag) => readAttribute(tag, 'name') === '_token');
  const csrfToken = tokenInput ? readAttribute(tokenInput, 'value') : null;
  if (!csrfToken) throw new Error('The isolated login page did not expose its CSRF form token.');
  const initialSessionCookie = cookies.get(sessionCookieName);
  if (
    !initialSessionCookie?.httpOnly
    || !initialSessionCookie.secure
    || !initialSessionCookie.value
    || !['lax', 'strict'].includes(initialSessionCookie.sameSite)
    || initialSessionCookie.path !== '/'
    || !initialSessionCookie.hostOnly
  ) {
    throw new Error('The configured session cookie was missing required security attributes.');
  }

  const loginResponse = await request(new URL('/login', app.origin), {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _token: csrfToken, email, password }),
  });
  collectCookies(loginResponse, cookies);

  const location = loginResponse.headers.get('location');
  if (![302, 303].includes(loginResponse.status) || !location) {
    throw new Error('The isolated test account could not authenticate.');
  }
  const redirect = new URL(location, app.origin);
  if (redirect.origin !== app.origin || redirect.pathname !== '/furimadeck-dashboard') {
    throw new Error('The isolated test account did not reach an authenticated destination.');
  }

  const sessionCookie = cookies.get(sessionCookieName);
  if (
    !sessionCookie?.httpOnly
    || !sessionCookie.secure
    || !sessionCookie.value
    || !['lax', 'strict'].includes(sessionCookie.sameSite)
    || sessionCookie.path !== '/'
    || !sessionCookie.hostOnly
  ) {
    throw new Error('The authenticated session cookie is missing required security attributes.');
  }
  if (sessionCookie.value === initialSessionCookie.value) {
    throw new Error('The login flow did not rotate the session identifier.');
  }

  const protectedPage = await request(new URL('/furimadeck-dashboard', app.origin));
  collectCookies(protectedPage, cookies);
  if (protectedPage.status !== 200) {
    throw new Error('The authenticated test account could not reach the protected dashboard.');
  }
  const protectedBody = await protectedPage.text();
  if (!protectedBody.includes(authenticatedDashboardMarker)) {
    throw new Error('The protected dashboard did not return its authenticated page marker.');
  }

  return { loginStatus: loginResponse.status, protectedStatus: protectedPage.status, sessionRotated: true };
}

async function main() {
  await prepareAuthenticatedZapSession({
    appUrl: process.env.ZAP_TEST_APP_URL,
    email: process.env.ZAP_TEST_EMAIL,
    password: process.env.ZAP_TEST_PASSWORD,
    sessionCookieName: process.env.ZAP_TEST_SESSION_COOKIE_NAME,
  });
  process.stdout.write('Disposable CI account authenticated, rotated its session, and reached the protected dashboard (HTTP 200).\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(() => {
    process.stderr.write('Authenticated DAST preflight failed; credentials and session data were suppressed.\n');
    process.exitCode = 1;
  });
}
