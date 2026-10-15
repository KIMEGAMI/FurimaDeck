import { platforms } from './platforms';
import { hasPrivacyConsent, PRIVACY_CONSENT_STORAGE_KEY, PRIVACY_CONSENT_VERSION } from './privacy-consent';
import './options.css';

type ThemePreference = 'system' | 'dark' | 'light';
type Session = { baseUrl: string; accessToken: string; userName: string };
type ActivityEntry = { action: string; occurred_at: string | null };

const byId = <T extends HTMLElement>(id: string): T => {
    const element = document.getElementById(id);
    if (!element) throw new Error(`画面要素が見つかりません: ${id}`);
    return element as T;
};

const state = byId<HTMLElement>('connection-state');
const detail = byId<HTMLElement>('connection-detail');
const disconnectButton = byId<HTMLButtonElement>('disconnect');
const activityList = byId<HTMLElement>('activity-list');
const themeSelect = byId<HTMLSelectElement>('theme');
const status = byId<HTMLElement>('status');
const themePreferences: ThemePreference[] = ['system', 'dark', 'light'];
const activityLabels: Record<string, string> = {
    'chrome_extension.device_paired': 'この端末を連携',
    'chrome_extension.device_revoked': '端末連携を解除',
    'chrome_extension.listing_recorded': '確認済みの掲載を記録',
    'chrome_extension.template_created': '定型文を作成',
    'chrome_extension.template_updated': '定型文を編集',
    'chrome_extension.template_deleted': '定型文を削除',
};

function safeBaseUrl(raw: string): string | null {
    try {
        const url = new URL(raw);
        const isLocal = url.protocol === 'http:' && url.hostname === '127.0.0.1';
        const isProduction = url.protocol === 'https:' && url.hostname === 'furimadeck.kimegami.jp';
        return isLocal || isProduction ? url.origin : null;
    } catch {
        return null;
    }
}

function applyTheme(theme: ThemePreference): void {
    document.documentElement.dataset.theme = theme;
}

function addListItem(list: HTMLElement, title: string, description: string): void {
    const item = document.createElement('li');
    const strong = document.createElement('strong');
    strong.textContent = title;
    const note = document.createElement('span');
    note.textContent = description;
    item.append(strong, note);
    list.append(item);
}

async function renderActivity(session: Session | undefined, baseUrl: string | null): Promise<number> {
    activityList.replaceChildren();
    if (!session || !baseUrl) {
        addListItem(activityList, '未連携', '端末連携後に、この拡張の操作履歴が表示されます。');
        return 0;
    }

    try {
        const response = await fetch(`${baseUrl}/api/extension/v1/activity`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${session.accessToken}` },
        });
        if (!response.ok) throw new Error('activity_unavailable');
        const result = await response.json() as { data: ActivityEntry[] };
        if (result.data.length === 0) {
            addListItem(activityList, '履歴はありません', '掲載記録や定型文の変更がここに表示されます。');
            return 0;
        }
        for (const entry of result.data) {
            const when = entry.occurred_at ? new Date(entry.occurred_at).toLocaleString('ja-JP') : '日時不明';
            addListItem(activityList, activityLabels[entry.action] ?? '拡張機能の操作', when);
        }
        return result.data.length;
    } catch {
        addListItem(activityList, '履歴を読み込めません', '接続を確認して、もう一度設定画面を開いてください。');
        return 0;
    }
}

async function render(): Promise<void> {
    const [stored, permissions, preference] = await Promise.all([
        chrome.storage.session.get(['furimadeckSession']),
        chrome.permissions.getAll(),
        chrome.storage.local.get(['theme']),
    ]);
    const session = stored.furimadeckSession as Session | undefined;
    const baseUrl = session ? safeBaseUrl(session.baseUrl) : null;
    const theme = themePreferences.includes(preference.theme as ThemePreference)
        ? preference.theme as ThemePreference
        : 'system';

    themeSelect.value = theme;
    applyTheme(theme);
    state.textContent = baseUrl && session?.accessToken ? '連携済み' : '未連携';
    detail.textContent = baseUrl && session?.accessToken
        ? `${session.userName} ・ ${baseUrl}（トークンは表示しません）`
        : '連携すると、自分のFurimaDeck商品だけを読み取ります。';
    disconnectButton.hidden = !(baseUrl && session?.accessToken);

    const permissionList = byId<HTMLElement>('permission-list');
    permissionList.replaceChildren();
    const grantedOrigins = permissions.origins ?? [];
    const productionGranted = grantedOrigins.includes('https://furimadeck.kimegami.jp/*');
    const localOrigin = baseUrl?.startsWith('http://127.0.0.1:') ? `${baseUrl}/*` : null;
    const localGranted = localOrigin ? grantedOrigins.includes(localOrigin) : grantedOrigins.some((origin) => origin.startsWith('http://127.0.0.1:'));
    addListItem(permissionList, '本番FurimaDeck', productionGranted ? '接続許可済み' : '未許可');
    addListItem(permissionList, '開発FurimaDeck', localGranted ? '接続許可済み' : '未許可');
    addListItem(permissionList, 'フリマページ判定', (permissions.permissions ?? []).includes('activeTab') ? '利用者操作時のみ' : '未許可');

    const platformList = byId<HTMLElement>('platform-list');
    platformList.replaceChildren();
    for (const platform of platforms) {
        const requiredOrigins = platform.formPermissionOrigins ?? [];
        const permitted = requiredOrigins.length > 0 && requiredOrigins.every((origin) => grantedOrigins.includes(origin));
        const message = platform.listingUrl
            ? permitted ? '任意権限許可済み（入力補助の試作）・自動出品なし' : '任意権限未許可（公式出品画面を開くのみ）'
            : '入力補助対象外';
        addListItem(platformList, platform.name, message);
    }

    const activityCount = await renderActivity(session, baseUrl);
    byId<HTMLAnchorElement>('privacy-link').href = `${baseUrl ?? 'https://furimadeck.kimegami.jp'}/privacy`;
    byId<HTMLButtonElement>('copy-diagnostics').dataset.activityCount = String(activityCount);
}

byId<HTMLButtonElement>('open-popup').addEventListener('click', async () => {
    try {
        await chrome.action.openPopup();
    } catch {
        status.textContent = 'Chrome右上の拡張機能アイコンからポップアップを開いてください。';
    }
});

themeSelect.addEventListener('change', async () => {
    const theme = themePreferences.includes(themeSelect.value as ThemePreference)
        ? themeSelect.value as ThemePreference
        : 'system';
    applyTheme(theme);
    await chrome.storage.local.set({ theme });
    status.textContent = '表示テーマを保存しました。';
});

byId<HTMLButtonElement>('copy-diagnostics').addEventListener('click', async (event) => {
    const button = event.currentTarget as HTMLButtonElement;
    const [stored, permissions] = await Promise.all([
        chrome.storage.session.get(['furimadeckSession']),
        chrome.permissions.getAll(),
    ]);
    const session = stored.furimadeckSession as Session | undefined;
    const baseUrl = session ? safeBaseUrl(session.baseUrl) : null;
    let apiStatus = 'not_connected';
    if (baseUrl && session?.accessToken) {
        try {
            const response = await fetch(`${baseUrl}/api/extension/v1/me`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${session.accessToken}` },
            });
            apiStatus = response.ok ? 'connected' : `http_${response.status}`;
        } catch {
            apiStatus = 'unreachable';
        }
    }
    const report = [
        'FurimaDeck Chrome拡張 診断情報',
        `version=${chrome.runtime.getManifest().version}`,
        `connection=${baseUrl && session?.accessToken ? 'paired' : 'not_paired'}`,
        `api=${apiStatus}`,
        `activity_count=${button.dataset.activityCount ?? '0'}`,
        `permission_activeTab=${(permissions.permissions ?? []).includes('activeTab') ? 'granted' : 'missing'}`,
        `permission_scripting=${(permissions.permissions ?? []).includes('scripting') ? 'granted' : 'missing'}`,
        `permission_storage=${(permissions.permissions ?? []).includes('storage') ? 'granted' : 'missing'}`,
    ].join('\n');
    try {
        await navigator.clipboard.writeText(report);
        status.textContent = '秘密値・商品情報を含まない診断情報をコピーしました。';
    } catch {
        status.textContent = '診断情報をコピーできませんでした。';
    }
});

disconnectButton.addEventListener('click', async () => {
    const stored = await chrome.storage.session.get(['furimadeckSession']);
    const session = stored.furimadeckSession as Session | undefined;
    const baseUrl = session ? safeBaseUrl(session.baseUrl) : null;
    if (baseUrl && session?.accessToken) {
        await fetch(`${baseUrl}/api/extension/v1/pairing/revoke`, {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${session.accessToken}` },
        }).catch(() => undefined);
    }
    await chrome.storage.local.remove(['connectedBaseUrl']);
    try {
        await chrome.scripting.unregisterContentScripts({ ids: ['furimadeck-product-link-bridge'] });
    } catch { /* The dynamic bridge may already have been removed. */ }
    await chrome.storage.session.remove(['furimadeckSession', 'pendingListingLaunch']);
    status.textContent = 'この端末の連携情報を消去しました。';
    await render();
});

const consentPanel = byId<HTMLElement>('privacy-consent');
const settingsContent = byId<HTMLElement>('settings-content');
const consentCheck = byId<HTMLInputElement>('privacy-consent-check');
const consentAccept = byId<HTMLButtonElement>('privacy-consent-accept');

consentCheck.addEventListener('change', () => {
    consentAccept.disabled = !consentCheck.checked;
});

consentAccept.addEventListener('click', async () => {
    if (!consentCheck.checked) return;
    await chrome.storage.local.set({ [PRIVACY_CONSENT_STORAGE_KEY]: PRIVACY_CONSENT_VERSION });
    consentPanel.hidden = true;
    settingsContent.hidden = false;
    await render();
});

void hasPrivacyConsent().then(async (accepted) => {
    consentPanel.hidden = accepted;
    settingsContent.hidden = !accepted;
    if (accepted) await render();
}).catch(() => {
    state.textContent = '設定を読み込めませんでした。';
});
