import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflowPath = new URL('../.github/workflows/security.yml', import.meta.url);
const workflow = readFileSync(workflowPath, 'utf8');
const ciWorkflowPath = new URL('../.github/workflows/ci.yml', import.meta.url);
const ciWorkflow = readFileSync(ciWorkflowPath, 'utf8');

test('both ZAP scans restrict the API to the exact scanner and loopback hosts', () => {
  const scanCommands = workflow.match(/zap-full-scan\.py[^\r\n]*/g) ?? [];

  assert.equal(scanCommands.length, 2, 'expected exactly one passive and one authenticated scan');
  for (const command of scanCommands) {
    assert.ok(
      command.includes('-z "-config api.addrs.addr.name=^(zap|localhost|127[.]0[.]0[.]1|::1|0:0:0:0:0:0:0:1|::ffff:127[.]0[.]0[.]1)(:[0-9]+)?$ -config api.addrs.addr.regex=true"'),
      'each ZAP scan must allow only its exact container hostname and loopback clients',
    );
  }

  assert.doesNotMatch(workflow, /api\.addrs\.addr\.name=\.\*/);
  assert.doesNotMatch(workflow, /api\.disablekey=false/);
});

test('the disposable ZAP session cookie is private, host-scoped, and removed on exit', () => {
  assert.ok(workflow.includes('auth_cookie_file=""'));
  assert.ok(workflow.includes('ZAP_TEST_AUTH_COOKIE_FILE="$auth_cookie_file"'));
  assert.ok(workflow.includes("stat -c '%a' -- \"$auth_cookie_file\""));
  assert.ok(workflow.includes('auth_header_env_file="$(mktemp '));
  assert.ok(workflow.includes('chmod 600 "$auth_header_env_file"'));
  assert.ok(workflow.includes('ZAP_AUTH_HEADER=Cookie'));
  assert.ok(workflow.includes('ZAP_AUTH_HEADER_VALUE=%s'));
  assert.ok(workflow.includes('ZAP_AUTH_HEADER_SITE=%s'));
  assert.ok(workflow.includes('--env-file "$auth_header_env_file"'));
  assert.ok(workflow.includes('rm -f -- "$auth_cookie_file"'));
  assert.ok(workflow.includes('rm -f -- "$auth_header_env_file"'));
  assert.ok(workflow.includes('--hook=/zap/wrk/furimadeck-zap-authenticated-scan-hook.py'));
  assert.doesNotMatch(workflow, /ZAP_AUTH_CREDENTIALS_FILE|-U "FurimaDeck CI scanner"/);
  assert.ok(workflow.includes("docker run --rm --entrypoint /bin/sh \"$zap_image\" -c 'id -u; id -g'"));
});

test('failed authenticated ZAP scans emit only aggregate access-log diagnostics before cleanup', () => {
  assert.ok(workflow.includes('auth_log_reported=false'));
  assert.ok(workflow.includes('if [ "$exit_status" -ne 0 ] && [ "$auth_log_reported" != true ]'));
  assert.ok(workflow.includes('node scripts/check-authenticated-zap-access-log.mjs "$auth_log" "$auth_log_offset" || true'));
  assert.ok(workflow.includes('trap cleanup_security_scan EXIT'));
  assert.ok(workflow.includes('auth_log_reported=true'));
});

test('CI and Security run on PR updates and main pushes without duplicate PR-branch push runs', () => {
  for (const [name, source] of [['CI', ciWorkflow], ['Security', workflow]]) {
    assert.match(source, /^  pull_request:\r?$/m, `${name} must validate pull requests`);
    const pushBranches = source.match(/^  push:\r?\n    branches:\r?\n((?:      - .+\r?\n)+)/m);
    assert.ok(pushBranches, `${name} must retain a push trigger`);
    assert.deepEqual(
      pushBranches[1].split(/\r?\n/).filter(Boolean).map((line) => line.trim()),
      ['- main'],
      `${name} must avoid a second run for PR branch pushes`,
    );
  }
});
