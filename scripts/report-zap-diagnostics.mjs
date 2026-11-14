import { readFileSync } from 'node:fs';

const safeRouteSegments = new Set([
  'admin',
  'assets',
  'auction-items',
  'auth',
  'billing',
  'build',
  'contact',
  'email',
  'edit',
  'favicon.ico',
  'forgot-password',
  'google',
  'login',
  'maintenance-login',
  'profile',
  'products',
  'register',
  'reset-password',
  'robots.txt',
  'sitemap.xml',
  'verification-notification',
]);

function safeLabel(value, fallback) {
  return String(value ?? fallback)
    .replace(/[^A-Za-z0-9 _.()-]/g, '')
    .slice(0, 120) || fallback;
}

function redactPath(uri) {
  try {
    const segments = new URL(uri).pathname.split('/');
    return segments.map((segment) => {
      if (!segment) return '';
      return safeRouteSegments.has(segment) ? segment : '{redacted}';
    }).join('/') || '/';
  } catch {
    return '<invalid-path>';
  }
}

function cookieNames(alert, instances) {
  if (!['10010', '90027'].includes(String(alert.pluginid ?? ''))) return [];

  const names = new Set();
  for (const instance of instances) {
    const header = instance['response-header'];
    if (typeof header !== 'string') continue;

    for (const line of header.split(/\r?\n/)) {
      const match = line.match(/^[\t ]*(set-cookie|cookie)[\t ]*:[\t ]*(.*)$/i);
      if (!match) continue;

      const field = match[1].toLowerCase();
      const candidates = field === 'cookie' ? match[2].split(';') : [match[2]];
      for (const candidate of candidates) {
        const cookie = candidate.trim().match(/^([A-Za-z0-9_-]{1,64})[\t ]*=/);
        if (cookie) names.add(cookie[1]);
      }
    }
  }

  return [...names].slice(0, 5);
}

export function formatZapDiagnostics(report) {
  if (!report || !Array.isArray(report.site)) {
    throw new TypeError('ZAP report must contain a site array');
  }

  const lines = [];
  for (const site of report.site) {
    for (const alert of Array.isArray(site.alerts) ? site.alerts : []) {
      const instances = Array.isArray(alert.instances) ? alert.instances : [];
      const samples = [...new Set(instances.slice(0, 3).map((instance) => (
        `${safeLabel(instance.method, 'GET')} ${redactPath(instance.uri || '')}`
      )))];
      const pluginId = /^\d+$/.test(String(alert.pluginid ?? '')) ? alert.pluginid : 'unknown-rule';
      const risk = safeLabel(alert.riskdesc, 'unknown-risk');
      const confidence = safeLabel(alert.confidencedesc ?? alert.confidenceDesc ?? alert.confidence, 'unknown-confidence');
      const name = safeLabel(alert.name ?? alert.alert, 'unnamed alert');

      lines.push([
        pluginId,
        risk,
        confidence,
        name,
        `instances=${instances.length}`,
        `cookie-names=${cookieNames(alert, instances).join(',') || 'not-reported'}`,
        `response-header-values=never-printed`,
        samples.join('; '),
      ].filter(Boolean).join(' | '));
    }
  }

  return lines.length > 0 ? lines.join('\n') : 'ZAP report contains no alert instances.';
}

const reportPath = process.argv[2];
if (reportPath) {
  try {
    const report = JSON.parse(readFileSync(reportPath, 'utf8'));
    process.stdout.write(`${formatZapDiagnostics(report)}\n`);
  } catch {
    process.stderr.write('ZAP diagnostics could not parse the report; no report content was printed.\n');
    process.exitCode = 1;
  }
}
