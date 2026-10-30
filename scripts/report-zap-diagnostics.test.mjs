import assert from 'node:assert/strict';
import test from 'node:test';
import { formatZapDiagnostics } from './report-zap-diagnostics.mjs';

test('summarizes traditional ZAP JSON without printing request evidence or response data', () => {
  const output = formatZapDiagnostics({
    site: [{
      alerts: [{
        pluginid: '10010',
        riskdesc: 'Low (Medium)',
        confidencedesc: 'Medium',
        name: 'Cookie No HttpOnly Flag',
        instances: [{
          uri: 'http://127.0.0.1:8002/products/2417/edit?email=private@example.test&token=secret-query',
          method: 'GET',
          attack: 'ATTACK_VALUE_DO_NOT_LOG',
          evidence: 'EVIDENCE_VALUE_DO_NOT_LOG',
          otherinfo: 'OTHER_INFO_DO_NOT_LOG',
          'param': 'PARAM_VALUE_DO_NOT_LOG',
          'response-header': 'Set-Cookie: XSRF-TOKEN=secret-cookie-value; HttpOnly',
          'response-body': 'BODY_VALUE_DO_NOT_LOG',
        }],
      }],
    }],
  });

  assert.match(output, /10010 \| Low \(Medium\) \| Medium \| Cookie No HttpOnly Flag/);
  assert.match(output, /GET \/products\/{redacted}\/edit/);
  assert.match(output, /cookie-names=XSRF-TOKEN/);
  assert.match(output, /response-header-values=never-printed/);
  for (const secret of [
    'private@example.test', 'secret-query', 'ATTACK_VALUE_DO_NOT_LOG',
    'EVIDENCE_VALUE_DO_NOT_LOG', 'PARAM_VALUE_DO_NOT_LOG', 'OTHER_INFO_DO_NOT_LOG', 'secret-cookie-value',
    'BODY_VALUE_DO_NOT_LOG',
  ]) {
    assert.doesNotMatch(output, new RegExp(secret));
  }
});

test('reports cookie names only from cookie headers and never reports cookie values', () => {
  const output = formatZapDiagnostics({
    site: [{
      alerts: [{
        pluginid: '90027',
        riskdesc: 'Informational',
        name: 'Cookie Slack Detector',
        instances: [{
          uri: 'http://127.0.0.1:8002/login',
          method: 'GET',
          param: 'FAKE_PARAM_DO_NOT_LOG',
          evidence: 'FAKE_EVIDENCE_DO_NOT_LOG',
          'response-header': 'HTTP/1.1 200 OK\r\nSet-Cookie: furimadeck_session=secret-session; Path=/\r\nCookie: XSRF-TOKEN=secret-xsrf; theme=secret-theme',
        }],
      }],
    }],
  });

  assert.match(output, /cookie-names=furimadeck_session,XSRF-TOKEN,theme/);
  for (const secret of [
    'secret-session', 'secret-xsrf', 'secret-theme',
    'FAKE_PARAM_DO_NOT_LOG', 'FAKE_EVIDENCE_DO_NOT_LOG',
  ]) {
    assert.doesNotMatch(output, new RegExp(secret));
  }
});

test('redacts non-route path segments and omits the query string', () => {
  const output = formatZapDiagnostics({
    site: [{
      alerts: [{
        pluginid: '10044',
        alert: 'Big Redirect Detected',
        instances: [{
          uri: 'http://127.0.0.1:8002/users/alice-private/reset-password?next=https%3A%2F%2Fexample.test',
          method: 'GET',
        }],
      }],
    }],
  });

  assert.match(output, /GET \/\{redacted\}\/\{redacted\}\/reset-password/);
  assert.doesNotMatch(output, /alice-private|example\.test|next=/);
});

test('handles an empty alert list but rejects a malformed report shape', () => {
  assert.equal(formatZapDiagnostics({ site: [] }), 'ZAP report contains no alert instances.');
  assert.throws(() => formatZapDiagnostics({ alerts: [] }), /site array/);
});
