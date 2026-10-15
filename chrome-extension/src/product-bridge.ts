const allowedPlatformIds = new Set(['mercari', 'rakuma', 'yahoo_flea_market', 'yahoo_auctions']);
type BridgeWindow = Window & { __furimadeckProductLinkBridgeInstalled?: boolean };
const bridgeWindow = window as BridgeWindow;

if (!bridgeWindow.__furimadeckProductLinkBridgeInstalled) {
    bridgeWindow.__furimadeckProductLinkBridgeInstalled = true;

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const link = target.closest<HTMLAnchorElement>('a[data-marketplace-shortcut][data-product-id]');
        if (!link) return;

        const productId = Number(link.dataset.productId);
        const platformId = link.dataset.marketplaceShortcut;
        if (!Number.isSafeInteger(productId) || productId < 1 || !platformId || !allowedPlatformIds.has(platformId)) return;

        event.preventDefault();

        void chrome.runtime.sendMessage({
            type: 'FURIMADECK_PRODUCT_LINK_CLICK',
            productId,
            platformId,
        }).catch(() => undefined);
    }, { capture: true });
}
