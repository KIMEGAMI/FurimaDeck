import assert from 'node:assert/strict';
import test from 'node:test';
import { summarizeAuthenticatedZapAccessLog } from './check-authenticated-zap-access-log.mjs';

test('accepts authenticated dashboard scan requests and reports status counts only', () => {
  const summary = summarizeAuthenticatedZapAccessLog([
    'GET /furimadeck-dashboard 200 1',
    'GET /furimadeck-dashboard 302 1',
    'GET /login 200 -',
  ].join('\n'));

  assert.equal(summary, 'Authenticated ZAP dashboard requests: total=2; session-cookie-present=2; HTTP statuses=200:1,302:1');
  assert.equal(summary.includes('cookie-value'), false);
});

test('fails when the authenticated scan never receives a successful protected response', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog('GET /furimadeck-dashboard 302 1'),
    /did not receive HTTP 200/,
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

test('fails closed on malformed access-log records', () => {
  assert.throws(
    () => summarizeAuthenticatedZapAccessLog('GET /furimadeck-dashboard 200'),
    /unexpected format/,
  );
});
