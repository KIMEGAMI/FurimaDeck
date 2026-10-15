export type PlatformId = 'mercari' | 'rakuma' | 'yahoo_flea_market' | 'yahoo_auctions' | 'mercari_shops';

export type Platform = {
    id: PlatformId;
    name: string;
    homeUrl: string;
    listingUrl?: string;
    hostAliases?: string[];
    formPermissionOrigins?: string[];
    operationLevel: 'manual_assist';
    authorizedPublish: false;
};

export const platforms: Platform[] = [
    { id: 'mercari', name: 'メルカリ', homeUrl: 'https://jp.mercari.com/', listingUrl: 'https://jp.mercari.com/sell/create', formPermissionOrigins: ['https://jp.mercari.com/*'], operationLevel: 'manual_assist', authorizedPublish: false },
    { id: 'rakuma', name: 'ラクマ', homeUrl: 'https://fril.jp/', listingUrl: 'https://fril.jp/item/new', formPermissionOrigins: ['https://fril.jp/*'], operationLevel: 'manual_assist', authorizedPublish: false },
    { id: 'yahoo_flea_market', name: 'Yahoo!フリマ', homeUrl: 'https://paypayfleamarket.yahoo.co.jp/', listingUrl: 'https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop', hostAliases: ['paypayfleamarket-sec.yahoo.co.jp'], formPermissionOrigins: ['https://paypayfleamarket-sec.yahoo.co.jp/*'], operationLevel: 'manual_assist', authorizedPublish: false },
    { id: 'yahoo_auctions', name: 'Yahoo!オークション', homeUrl: 'https://auctions.yahoo.co.jp/', listingUrl: 'https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0', formPermissionOrigins: ['https://auctions.yahoo.co.jp/*'], operationLevel: 'manual_assist', authorizedPublish: false },
    { id: 'mercari_shops', name: 'メルカリShops', homeUrl: 'https://mercari-shops.com/', operationLevel: 'manual_assist', authorizedPublish: false },
];

export function detectPlatform(hostname: string): Platform | null {
    const host = hostname.toLowerCase().replace(/\.$/, '');
    return platforms.find((platform) => {
        const canonicalHost = new URL(platform.homeUrl).hostname;
        return [canonicalHost, ...(platform.hostAliases ?? [])]
            .some((domain) => host === domain || host.endsWith(`.${domain}`));
    }) ?? null;
}
