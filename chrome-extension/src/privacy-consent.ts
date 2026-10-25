export const PRIVACY_CONSENT_STORAGE_KEY = 'furimadeckPrivacyConsent';
export const PRIVACY_CONSENT_VERSION = '2026-10-15-marketplace-site-processing-disclosure';

export async function hasPrivacyConsent(): Promise<boolean> {
    const stored = await chrome.storage.local.get([PRIVACY_CONSENT_STORAGE_KEY]);
    return stored[PRIVACY_CONSENT_STORAGE_KEY] === PRIVACY_CONSENT_VERSION;
}
