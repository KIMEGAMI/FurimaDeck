interface ChromeStorageArea {
    get(keys: string[]): Promise<Record<string, unknown>>;
    set(values: Record<string, unknown>): Promise<void>;
    remove(keys: string[]): Promise<void>;
}

declare const chrome: {
    runtime: {
        getURL(path: string): string;
        openOptionsPage(): Promise<void>;
        sendMessage(message: Record<string, unknown>): Promise<unknown>;
        onInstalled: { addListener(callback: () => void): void };
        onMessage: { addListener(callback: (message: Record<string, unknown>, sender: { url?: string; tab?: { url?: string } }, sendResponse: (response: unknown) => void) => boolean | void): void };
    };
    storage: { session: ChromeStorageArea; local: ChromeStorageArea };
    identity: {
        getRedirectURL(path?: string): string;
        launchWebAuthFlow(options: { url: string; interactive: boolean }): Promise<string>;
    };
    permissions: {
        request(options: { origins: string[] }): Promise<boolean>;
        contains(options: { origins: string[] }): Promise<boolean>;
        getAll(): Promise<{ origins?: string[]; permissions?: string[] }>;
    };
    tabs: {
        create(options: { url: string; active?: boolean }): Promise<{ id?: number }>;
        update(tabId: number, properties: { url?: string; active?: boolean }): Promise<{ id?: number; status?: string; url?: string }>;
        get(tabId: number): Promise<{ id?: number; status?: string; url?: string }>;
        query(options: { active: boolean; lastFocusedWindow: boolean }): Promise<{ id?: number; url?: string }[]>;
        sendMessage(tabId: number, message: Record<string, unknown>): Promise<unknown>;
        onUpdated: { addListener(callback: (tabId: number, changeInfo: { status?: string }, tab: { id?: number; url?: string }) => void): void };
    };
    scripting: {
        executeScript(options: { target: { tabId: number }; files: string[] }): Promise<unknown[]>;
        executeScript<T extends (...args: never[]) => unknown>(options: { target: { tabId: number }; func: T; args: Parameters<T> }): Promise<{ result?: ReturnType<T> }[]>;
        registerContentScripts(scripts: { id: string; matches: string[]; js: string[]; runAt: 'document_start' | 'document_end' | 'document_idle'; persistAcrossSessions?: boolean }[]): Promise<void>;
        unregisterContentScripts(options: { ids: string[] }): Promise<void>;
    };
    action: { openPopup(): Promise<void> };
};
