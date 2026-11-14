import assert from 'node:assert/strict';
import test from 'node:test';
import { summarizeAuthenticatedZapAccessLog } from './check-authenticated-zap-access-log.mjs';

test('accepts authenticated dashboard scan requests and reports status counts only', () => {
  const summary = summarizeAuthenticatedZapAccessLog([
    'GET /furimadeck-dashboard 200 1',
    'GET /furimadeck-dashboard 200 1',
    'POST /logout 419 1',
    'GET /login 200 -',
    'POST /login 302 1',
  ].join('\n'));

  assert.equal(summary, 'Authenticated ZAP dashboard requests: total=2; session-cookie-present=2; HTTP statuses=200:2; /logout requests=1; /logout method-status=POST:419:1; /login requests=2; /login successful-posts=1; /login POST session-cookie-present=1; missing=0; /login method-status=GET:200:1,POST:302:1');
  assert.equal(summary.includes('cookie-value'), false);
});

test('fails closed when any authenticated dashboard request redirects or otherwise fails', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog([
      'GET /furimadeck-dashboard 200 1',
      'GET /furimadeck-dashboard 302 1',
    ].join('\n')),
    /One or more authenticated requests to the protected dashboard did not return HTTP 200/,
  );
});

test('fails when any protected-dashboard request omits the isolated session cookie', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog([
      'GET /furimadeck-dashboard 200 1',
      'GET /furimadeck-dashboard 302 -',
    ].join('\n')),
    /did not include the isolated session cookie/,
  );
});

test('fails when the authenticated scan never requests the protected dashboard', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog('GET /login 200 -'),
    /made no requests to the protected dashboard/,
  );
});

test('accepts preflight-authenticated cookie scans without ZAP form-login attempts', () => {
  const summary = summarizeAuthenticatedZapAccessLog([
    'GET /furimadeck-dashboard 200 1',
    'GET /furimadeck-dashboard 200 1',
    'GET /products 200 1',
  ].join('\n'));

  assert.match(summary, /total=2; session-cookie-present=2; HTTP statuses=200:2/);
  assert.match(summary, /successful-posts=0/);
});

test('fails closed on malformed access-log records', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog('GET /furimadeck-dashboard 200'),
    /unexpected format/,
  );
});

test('does not disclose unrecognized request paths in diagnostics', () => {
  const summary = summarizeAuthenticatedZapAccessLog([
    'GET /furimadeck-dashboard 200 1',
    'POST /login 302 1',
    'POST /logout 302 1',
    'GET /users/private@example.invalid 302 1',
  ].join('\n'));

  assert.equal(summary.includes('private@example.invalid'), false);
  assert.match(summary, /\/logout requests=1; \/logout method-status=POST:302/);
});

test('reports only aggregate cookie-presence counts for failed login posts', () => {
  assert.throws(() => summarizeAuthenticatedZapAccessLog([
    'GET /furimadeck-dashboard 302 -',
    'GET /login 200 1',
    'POST /login 419 1',
    'POST /login 419 -',
  ].join('\n')), (error) => {
    assert.match(error.message, /\/login POST session-cookie-present=1; missing=1/);
    assert.doesNotMatch(error.message, /session=[A-Za-z0-9]/);
    return true;
  });
});
