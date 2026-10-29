import assert from 'node:assert/strict';
import test from 'node:test';
import { findUnpinnedWorkflowReferences } from './check-github-actions-pinned.mjs';

const actionSha = 'a'.repeat(40);
const imageDigest = 'b'.repeat(64);

test('accepts immutable action SHAs and pinned container images', () => {
  const findings = findUnpinnedWorkflowReferences(
    `steps:\n  - uses: actions/checkout@${actionSha} # reviewed release\nservices:\n  db:\n    image: mariadb@sha256:${imageDigest}\n`,
  );

  assert.deepEqual(findings, []);
});

test('rejects mutable action tags and unpinned container tags', () => {
  const findings = findUnpinnedWorkflowReferences(
    'steps:\n  - uses: actions/checkout@v4\nservices:\n  db:\n    image: mariadb:11.4\n',
    'workflow.yml',
  );

  assert.equal(findings.length, 2);
  assert.match(findings[0], /workflow.yml:2: uses must use a full 40-character commit SHA/);
  assert.match(findings[1], /workflow.yml:5: image must use an immutable sha256 image digest/);
});

test('allows local actions and accepts digest-pinned Docker actions', () => {
  const findings = findUnpinnedWorkflowReferences(
    `steps:\n  - uses: ./.github/actions/local-check\n  - uses: docker://semgrep/semgrep@sha256:${imageDigest}\n`,
  );

  assert.deepEqual(findings, []);
});

test('rejects remote action references without a repository revision', () => {
  const findings = findUnpinnedWorkflowReferences('steps:\n  - uses: actions/checkout\n');

  assert.equal(findings.length, 1);
});
