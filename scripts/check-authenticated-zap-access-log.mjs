import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const protectedDashboardPaths = new Set(['/furimadeck-dashboard', '/furimadeck-dashboard/']);

export function summarizeAuthenticatedZapAccessLog(logText) {
  const statusCounts = new Map();
  let dashboardRequests = 0;
  let requestsWithSessionCookie = 0;

  for (const line of logText.split(/\r?\n/)) {
    if (line === '') continue;

    const fields = line.split(' ');
    if (fields.length !== 4 || !/^[1-5][0-9]{2}$/.test(fields[2])) {
      throw new Error('The sanitized authenticated DAST access log has an unexpected format.');
    }

    const [method, path, status, sessionCookiePresent] = fields;
    if (method !== 'GET' || !protectedDashboardPaths.has(path)) continue;

    dashboardRequests += 1;
    statusCounts.set(status, (statusCounts.get(status) ?? 0) + 1);
    if (sessionCookiePresent === '1') requestsWithSessionCookie += 1;
  }

  if (dashboardRequests === 0) {
    throw new Error('Authenticated ZAP made no requests to the protected dashboard.');
  }
  if (requestsWithSessionCookie !== dashboardRequests) {
    throw new Error('One or more protected-dashboard requests did not include the isolated session cookie.');
  }
  if ((statusCounts.get('200') ?? 0) === 0) {
    throw new Error('Authenticated ZAP did not receive HTTP 200 from the protected dashboard.');
  }

  const statuses = [...statusCounts]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([status, count]) => `${status}:${count}`)
    .join(',');

  return `Authenticated ZAP dashboard requests: total=${dashboardRequests}; session-cookie-present=${requestsWithSessionCookie}; HTTP statuses=${statuses}`;
}

async function main() {
  const [logPath, offsetText] = process.argv.slice(2);
  if (typeof logPath !== 'string' || !/^[0-9]+$/.test(offsetText ?? '')) {
    throw new Error('A sanitized access-log path and non-negative byte offset are required.');
  }

  const offset = Number(offsetText);
  if (!Number.isSafeInteger(offset)) throw new Error('The access-log byte offset is invalid.');

  const bytes = await readFile(logPath);
  if (offset > bytes.length) throw new Error('The access-log byte offset exceeds the current log size.');

  process.stdout.write(`${summarizeAuthenticatedZapAccessLog(bytes.subarray(offset).toString('utf8'))}\n`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : 'Authenticated DAST access-log validation failed.'}\n`);
    process.exitCode = 1;
  });
}
