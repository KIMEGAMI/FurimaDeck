import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflowPath = new URL('../.github/workflows/security.yml', import.meta.url);
const workflow = readFileSync(workflowPath, 'utf8');

test('both ZAP scans restrict the API to loopback hosts', () => {
  const scanCommands = workflow.match(/zap-full-scan\.py[^\r\n]*/g) ?? [];

  assert.equal(scanCommands.length, 2, 'expected exactly one passive and one authenticated scan');
  for (const command of scanCommands) {
    assert.ok(
      command.includes('-z "-config api.addrs.addr.name=^(localhost|127[.]0[.]0[.]1|::1|::ffff:127[.]0[.]0[.]1)(:[0-9]+)?$ -config api.addrs.addr.regex=true"'),
      'each ZAP scan must allow only the local scanner API client',
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
});
