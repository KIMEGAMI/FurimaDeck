import { platforms, type Platform, type PlatformId } from './platforms.ts';

export type PlatformCapability = 'manual_copy' | 'open_official_site' | 'record_confirmed_listing';

export type MarketplaceDraftSource = {
    platformId: PlatformId;
    title: string;
    description: string | null;
    price: number | null;
    condition: string;
    category: string | null;
    shippingMethod: string | null;
    shippingFee: number | null;
    reviewFields: readonly string[];
};

export type PreparedMarketplaceDraft = MarketplaceDraftSource & {
    platform: Platform;
    capabilities: readonly PlatformCapability[];
    operationLevel: 'manual_assist';
    authorizedPublish: false;
};

export type MarketplaceAdapter = {
    id: PlatformId;
    platform: Platform;
    capabilities: readonly PlatformCapability[];
    matchUrl(url: URL): boolean;
    prepareDraft(source: MarketplaceDraftSource): PreparedMarketplaceDraft;
};

const MANUAL_CAPABILITIES: readonly PlatformCapability[] = [
    'manual_copy',
    'open_official_site',
    'record_confirmed_listing',
];

function matchesPlatformHost(platform: Platform, hostname: string): boolean {
    const host = hostname.toLowerCase().replace(/\.$/, '');
    const canonicalHost = new URL(platform.homeUrl).hostname;
    return [canonicalHost, ...(platform.hostAliases ?? [])]
        .some((domain) => host === domain || host.endsWith(`.${domain}`));
}

function createAdapter(platform: Platform): MarketplaceAdapter {
    return {
        id: platform.id,
        platform,
        capabilities: MANUAL_CAPABILITIES,
        matchUrl(url) {
            return url.protocol === 'https:' && matchesPlatformHost(platform, url.hostname);
        },
        prepareDraft(source) {
            if (source.platformId !== platform.id) {
                throw new Error('選択した出品先とドラフトの出品先が一致しません。');
            }

            // FurimaDeck already stores listing-specific values. Keep them intact;
            // unknown marketplace categories or shipping rules must remain for manual review.
            return {
                ...source,
                reviewFields: [...new Set(source.reviewFields)],
                platform,
                capabilities: MANUAL_CAPABILITIES,
                operationLevel: 'manual_assist',
                authorizedPublish: false,
            };
        },
    };
}

export const marketplaceAdapters: Record<PlatformId, MarketplaceAdapter> = Object.fromEntries(
    platforms.map((platform) => [platform.id, createAdapter(platform)]),
) as Record<PlatformId, MarketplaceAdapter>;

export function adapterForPlatform(id: PlatformId): MarketplaceAdapter {
    return marketplaceAdapters[id];
}

export function adapterForUrl(url: URL): MarketplaceAdapter | null {
    return Object.values(marketplaceAdapters).find((adapter) => adapter.matchUrl(url)) ?? null;
}
