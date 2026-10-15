import assert from 'node:assert/strict';
import { mkdir, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { chromium } from '@playwright/test';

const extensionPath = process.env.FURIMADECK_EXTENSION_PATH?.trim();
if (!extensionPath) throw new Error('FURIMADECK_EXTENSION_PATH must point to an extracted Chrome Web Store package.');

const packagePath = path.resolve(extensionPath);
const profileRoot = path.resolve(import.meta.dirname, `.package-smoke-profile-${process.pid}-${Date.now()}`);
const profilePrefix = `${path.resolve(import.meta.dirname)}${path.sep}`;
let context;

try {
    await mkdir(profileRoot);
    const manifest = JSON.parse(await readFile(path.join(packagePath, 'manifest.json'), 'utf8'));
    assert.equal(manifest.manifest_version, 3);
    assert.deepEqual(manifest.optional_host_permissions, [
        'https://furimadeck.kimegami.jp/*',
        'https://jp.mercari.com/*',
        'https://fril.jp/*',
        'https://paypayfleamarket-sec.yahoo.co.jp/*',
        'https://auctions.yahoo.co.jp/*',
    ]);
    assert.equal(Object.hasOwn(manifest, 'host_permissions'), false);
    assert.equal(Object.hasOwn(manifest, 'content_scripts'), false);

    context = await chromium.launchPersistentContext(path.join(profileRoot, 'profile'), {
        channel: 'chrome',
        headless: true,
        ignoreDefaultArgs: ['--disable-extensions'],
        args: ['--enable-unsafe-extension-debugging'],
    });
    const browser = context.browser();
    if (!browser) throw new Error('Playwright did not expose the launched Chrome browser.');
    const browserSession = await browser.newBrowserCDPSession();
    let extensionId;
    try {
        ({ id: extensionId } = await browserSession.send('Extensions.loadUnpacked', { path: packagePath }));
    } finally {
        await browserSession.detach();
    }
    const worker = context.serviceWorkers()[0] ?? await context.waitForEvent('serviceworker', { timeout: 10_000 }).catch(() => null);
    if (!worker) throw new Error('Chrome did not expose the packaged extension service worker.');
    assert.equal(new URL(worker.url()).host, extensionId);
    const outboundRequests = [];
    context.on('request', (request) => {
        try {
            const url = new URL(request.url());
            if (url.hostname === 'furimadeck.kimegami.jp') outboundRequests.push(`${url.origin}${url.pathname}`);
        } catch { /* Ignore non-URL browser requests. */ }
    });

    const popup = await context.newPage();
    const pageErrors = [];
    popup.on('pageerror', (error) => pageErrors.push(error.message));
    await popup.goto(`chrome-extension://${extensionId}/popup.html`);
    assert.equal(await popup.title(), 'FurimaDeck 出品アシスト');
    await popup.waitForFunction(() => document.querySelector('#privacy-consent')?.hidden === false, null, { timeout: 5_000 })
        .catch(() => undefined);
    assert.deepEqual(pageErrors, [], `The packaged popup must initialize without errors: ${pageErrors.join('; ')}`);
    const disclosureState = await popup.evaluate(async () => ({
        disclosureHidden: document.querySelector('#privacy-consent')?.hidden,
        featureHidden: document.querySelector('#feature-content')?.hidden,
        storedConsent: (await chrome.storage.local.get(['furimadeckPrivacyConsent'])).furimadeckPrivacyConsent ?? null,
    }));
    assert.equal(await popup.locator('#privacy-consent').isVisible(), true, `The store package must show data disclosure before feature use: ${JSON.stringify(disclosureState)}`);
    assert.equal(await popup.locator('#feature-content').isHidden(), true, 'Features must remain gated until consent.');
    assert.equal(await popup.locator('#privacy-consent-accept').isDisabled(), true, 'Consent must require an affirmative checkbox action.');
    await popup.waitForTimeout(250);
    assert.deepEqual(outboundRequests, [], 'The package must not contact FurimaDeck before consent.');

    process.stdout.write(`Chrome Web Store package runtime smoke passed: v${manifest.version}, service worker starts, disclosure gates features, and no pre-consent FurimaDeck request.\n`);
} finally {
    await context?.close();
    const resolvedProfileRoot = path.resolve(profileRoot);
    if (!resolvedProfileRoot.startsWith(profilePrefix)) {
        throw new Error('Refusing to remove a test profile outside the system temporary directory.');
    }
    await rm(resolvedProfileRoot, { recursive: true, force: true });
}
