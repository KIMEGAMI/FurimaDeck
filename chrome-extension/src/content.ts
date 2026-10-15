chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message.type !== 'FURIMADECK_READ_HOSTNAME') return;

    // Deliberately return only the host. Page text, user identity, and listing data are not read.
    sendResponse({ hostname: window.location.hostname });
});
