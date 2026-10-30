import { chmod, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

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
    if (expiresImmediately) {
      cookies.delete(name);
    } else {
      cookies.set(name, {
        value,
        httpOnly: attributes.some((attribute) => /^\s*httponly\s*$/i.test(attribute)),
        secure: attributes.some((attribute) => /^\s*secure\s*$/i.test(attribute)),
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
  cookieFile,
  fetchImpl = fetch,
}) {
  if (typeof email !== 'string' || email.length === 0 || typeof password !== 'string' || password.length === 0) {
    throw new Error('A disposable test account is required.');
  }
  if (typeof cookieFile !== 'string' || cookieFile.length === 0) {
    throw new Error('A temporary cookie output path is required.');
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
  if (!initialSessionCookie?.httpOnly || !initialSessionCookie.secure) {
    throw new Error('The configured session cookie was missing HttpOnly or Secure.');
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
  if (redirect.origin !== app.origin || redirect.pathname === '/login') {
    throw new Error('The isolated test account did not reach an authenticated destination.');
  }

  const protectedPage = await request(new URL('/furimadeck-dashboard', app.origin));
  collectCookies(protectedPage, cookies);
  if (protectedPage.status !== 200) {
    throw new Error('The authenticated test account could not reach the protected dashboard.');
  }

  const sessionCookie = cookies.get(sessionCookieName);
  if (!sessionCookie) {
    throw new Error('The isolated authenticated session cookie was not established.');
  }

  await writeFile(cookieFile, `${sessionCookieName}=${sessionCookie.value}`, { encoding: 'utf8', mode: 0o600 });
  await chmod(cookieFile, 0o600);

  return { loginStatus: loginResponse.status, protectedStatus: protectedPage.status };
}

async function main() {
  await prepareAuthenticatedZapSession({
    appUrl: process.env.ZAP_TEST_APP_URL,
    email: process.env.ZAP_TEST_EMAIL,
    password: process.env.ZAP_TEST_PASSWORD,
    sessionCookieName: process.env.ZAP_TEST_SESSION_COOKIE_NAME,
    cookieFile: process.env.ZAP_AUTH_COOKIE_FILE,
  });
  process.stdout.write('Disposable CI account reached the protected dashboard (HTTP 200); session data was not printed.\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(() => {
    process.stderr.write('Authenticated DAST preflight failed; credentials and session data were suppressed.\n');
    process.exitCode = 1;
  });
}
