import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const githubMetadataDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '../.github');

function yamlFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) {
      return yamlFiles(path);
    }
    return /\.ya?ml$/i.test(entry.name) ? [path] : [];
  });
}

export function findUnpinnedWorkflowReferences(contents, source = '<workflow>') {
  const findings = [];

  contents.split(/\r?\n/).forEach((line, index) => {
    const match = line.match(/^\s*(?:-\s*)?(uses|image):\s*(.*?)\s*$/);
    if (!match) {
      return;
    }

    let reference = match[2].replace(/\s+#.*$/, '').trim();
    if (
      (reference.startsWith("'") && reference.endsWith("'"))
      || (reference.startsWith('"') && reference.endsWith('"'))
    ) {
      reference = reference.slice(1, -1);
    }

    if (match[1] === 'uses' && (reference.startsWith('./') || reference.startsWith('../'))) {
      return;
    }

    const pinned = match[1] === 'image'
      ? /^[^@\s]+@sha256:[a-f0-9]{64}$/i.test(reference)
      : reference.startsWith('docker://')
        ? /^docker:\/\/[^@\s]+@sha256:[a-f0-9]{64}$/i.test(reference)
        : /^[a-z0-9_.-]+\/[a-z0-9_.-]+(?:\/[a-z0-9_.-]+)*@[a-f0-9]{40}$/i.test(reference);

    if (!pinned) {
      const requirement = match[1] === 'image' || reference.startsWith('docker://')
        ? 'an immutable sha256 image digest'
        : 'a full 40-character commit SHA';
      findings.push(`${source}:${index + 1}: ${match[1]} must use ${requirement}: ${reference}`);
    }
  });

  return findings;
}

export function scanGithubMetadata(directory = githubMetadataDirectory) {
  const files = yamlFiles(directory).sort();
  const findings = files.flatMap((path) => (
    findUnpinnedWorkflowReferences(readFileSync(path, 'utf8'), path.slice(directory.length + 1))
  ));

  return { files, findings };
}

const invokedPath = process.argv[1] ? resolve(process.argv[1]) : '';
if (invokedPath === fileURLToPath(import.meta.url)) {
  const { files, findings } = scanGithubMetadata();
  if (findings.length > 0) {
    process.stderr.write(`${findings.join('\n')}\n`);
    process.exitCode = 1;
  } else {
    process.stdout.write(`All ${files.length} GitHub metadata YAML files pin actions and container images immutably.\n`);
  }
}
