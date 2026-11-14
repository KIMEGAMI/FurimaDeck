import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflowPath = new URL('../.github/workflows/security.yml', import.meta.url);
const workflow = readFileSync(workflowPath, 'utf8');

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

test('the disposable ZAP credential file is private and removed on exit', () => {
  assert.match(workflow, /auth_credentials_file=""/);
  assert.ok(workflow.includes('auth_credentials_file="$(mktemp '));
  assert.ok(workflow.includes('chmod 600 "$auth_credentials_file"'));
  assert.ok(workflow.includes("stat -c '%a' -- \"$auth_credentials_file\""));
  assert.ok(workflow.includes('rm -f -- "$auth_credentials_file"'));
  assert.ok(workflow.includes('unset zap_auth_email zap_auth_password'));
  assert.ok(workflow.includes('auth_runtime_credentials_file=""'));
  assert.ok(workflow.includes("sudo install -o \"$zap_container_uid\" -g \"$zap_container_gid\" -m 600 -- \"$auth_credentials_file\" \"$auth_runtime_credentials_file\""));
  assert.ok(workflow.includes("stat -c '%a:%u:%g' -- \"$auth_runtime_credentials_file\""));
  assert.ok(workflow.includes('ZAP_AUTH_CREDENTIALS_FILE=/zap/wrk/furimadeck-zap-auth-credentials'));
  assert.ok(workflow.includes('--hook=/zap/wrk/furimadeck-zap-authenticated-scan-hook.py'));
  assert.ok(workflow.includes("docker run --rm --entrypoint /bin/sh \"$zap_image\" -c 'id -u; id -g'"));
});

test('failed authenticated ZAP scans emit only aggregate access-log diagnostics before cleanup', () => {
  assert.ok(workflow.includes('auth_log_reported=false'));
  assert.ok(workflow.includes('if [ "$exit_status" -ne 0 ] && [ "$auth_log_reported" != true ]'));
  assert.ok(workflow.includes('node scripts/check-authenticated-zap-access-log.mjs "$auth_log" "$auth_log_offset" || true'));
  assert.ok(workflow.includes('trap cleanup_security_scan EXIT'));
  assert.ok(workflow.includes('auth_log_reported=true'));
});
