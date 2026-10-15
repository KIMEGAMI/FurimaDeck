import type { PlatformId } from './platforms';

export type MarketplaceImage = { name: string; type: string; dataUrl: string };
export type MarketplacePrefillValues = {
    platformId: PlatformId;
    title: string;
    description: string | null;
    price: number | null;
    category: string | null;
    condition: string | null;
    shippingMethod: string | null;
    shippingFee: number | null;
    shippingPayer: string | null;
    senderRegion: string | null;
    dispatchDays: number | null;
    shippingSize: string | null;
    shippingWeightGrams: number | null;
    saleFormat: string | null;
    listingPeriodDays: number | null;
    returnPolicy: string | null;
    purchaseApplication: string | null;
    quantity: number | null;
    janEan: string | null;
    isbn: string | null;
    manufacturerModelNumber: string | null;
    serialNumber: string | null;
    attributes: { label: string; value: string }[];
    images: MarketplaceImage[];
    imageWarning: string | null;
};

/** Serialized into marketplace pages. It intentionally uses only visible labels, never private selectors or platform APIs. */
export async function prefillMarketplaceForm(values: MarketplacePrefillValues): Promise<{
    filled: string[];
    missing: string[];
    ambiguous: string[];
    existing: string[];
    imageWarning?: string;
    page: 'listing' | 'other';
}> {
    const listingRoutes: Record<PlatformId, { host: string; path: RegExp }> = {
        mercari: { host: 'jp.mercari.com', path: /^\/sell\/create(?:\/|$)/ },
        rakuma: { host: 'fril.jp', path: /^\/item\/new\/?$/ },
        yahoo_flea_market: { host: 'paypayfleamarket-sec.yahoo.co.jp', path: /^\/item\/add\/?$/ },
        yahoo_auctions: { host: 'auctions.yahoo.co.jp', path: /^\/sell\/jp\/show\/submit\/?$/ },
        mercari_shops: { host: 'mercari-shops.com', path: /^\/seller\/items\/new\/?$/ },
    };
    const route = listingRoutes[values.platformId];
    if (!route || location.protocol !== 'https:' || location.hostname !== route.host || !route.path.test(location.pathname)) {
        return { filled: [], missing: [], ambiguous: [], existing: [], page: 'other' };
    }

    type FormControl = HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | HTMLElement;
    const result = { filled: [] as string[], missing: [] as string[], ambiguous: [] as string[], existing: [] as string[], page: 'listing' as const };
    const visible = (element: HTMLElement): boolean => {
        const style = getComputedStyle(element);
        const readOnly = 'readOnly' in element && element.readOnly;
        return !element.hasAttribute('disabled') && !readOnly && style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    };
    const controls = (): FormControl[] => Array.from(document.querySelectorAll<FormControl>(
        'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea, select, [contenteditable="true"], [role="textbox"]',
    )).filter((control) => visible(control)
        && control.getAttribute('aria-disabled') !== 'true'
        && control.getAttribute('aria-readonly') !== 'true'
        && !(control instanceof HTMLElement && control.isContentEditable && control.parentElement?.isContentEditable));
    const labelFor = (control: HTMLElement): string => {
        const labels = 'labels' in control && control.labels ? Array.from(control.labels, (label) => label.textContent ?? '').join(' ') : '';
        const labelledBy = (control.getAttribute('aria-labelledby') ?? '').split(/\s+/)
            .map((id) => document.getElementById(id)?.textContent ?? '').join(' ');
        return [control.getAttribute('aria-label') ?? '', labelledBy, control.getAttribute('placeholder') ?? '', control.getAttribute('name') ?? '', control.id,
            labels, control.closest('label')?.textContent ?? '']
            .join(' ').replace(/\s+/g, ' ').trim();
    };
    const titlePatterns = [/商品名/, /タイトル/];
    const fields: { key: string; patterns: RegExp[]; value: string | null; exactOption?: boolean }[] = [
        { key: 'title', patterns: titlePatterns, value: values.title.trim() || null },
        { key: 'description', patterns: [/商品説明/, /商品の説明/, /説明文/, /説明/], value: values.description?.trim() || null },
        { key: 'price', patterns: [/価格/, /開始価格/], value: values.price !== null && Number.isSafeInteger(values.price) && values.price >= 0 ? String(values.price) : null },
        { key: 'category', patterns: [/カテゴリ/, /カテゴリー/], value: values.category?.trim() || null, exactOption: true },
        { key: 'condition', patterns: [/商品状態/, /商品の状態/, /^状態$/], value: values.condition?.trim() || null, exactOption: true },
        { key: 'shipping_method', patterns: [/配送方法/, /発送方法/], value: values.shippingMethod?.trim() || null, exactOption: true },
        { key: 'shipping_fee', patterns: [/送料$/], value: values.shippingFee !== null && Number.isSafeInteger(values.shippingFee) && values.shippingFee >= 0 ? String(values.shippingFee) : null },
        { key: 'shipping_payer', patterns: [/送料負担/, /送料の負担/, /配送費負担/], value: values.shippingPayer?.trim() || null, exactOption: true },
        { key: 'sender_region', patterns: [/発送元地域/, /発送元の地域/], value: values.senderRegion?.trim() || null, exactOption: true },
        { key: 'dispatch_days', patterns: [/発送までの日数/, /発送日数/], value: values.dispatchDays !== null && Number.isSafeInteger(values.dispatchDays) && values.dispatchDays >= 0 ? String(values.dispatchDays) : null, exactOption: true },
        { key: 'shipping_size', patterns: [/配送サイズ/, /発送サイズ/], value: values.shippingSize?.trim() || null, exactOption: true },
        { key: 'shipping_weight_grams', patterns: [/配送重量/, /発送重量/, /重量（g）/, /重量\(g\)/], value: values.shippingWeightGrams !== null && Number.isSafeInteger(values.shippingWeightGrams) && values.shippingWeightGrams >= 0 ? String(values.shippingWeightGrams) : null },
        { key: 'sale_format', patterns: [/販売形式/, /出品形式/], value: values.saleFormat?.trim() || null, exactOption: true },
        { key: 'listing_period_days', patterns: [/掲載期間/, /出品期間/], value: values.listingPeriodDays !== null && Number.isSafeInteger(values.listingPeriodDays) && values.listingPeriodDays > 0 ? String(values.listingPeriodDays) : null },
        { key: 'return_policy', patterns: [/返品方針/, /返品条件/], value: values.returnPolicy?.trim() || null },
        { key: 'purchase_application', patterns: [/購入申請/], value: values.purchaseApplication?.trim() || null, exactOption: true },
        { key: 'quantity', patterns: [/出品数/, /販売数量/, /数量/], value: values.quantity !== null && Number.isSafeInteger(values.quantity) && values.quantity > 0 ? String(values.quantity) : null },
        { key: 'jan_ean', patterns: [/JANコード/, /JAN・EAN/, /バーコード/], value: values.janEan?.trim() || null },
        { key: 'isbn', patterns: [/ISBN/], value: values.isbn?.trim() || null },
        { key: 'manufacturer_model_number', patterns: [/メーカー型番/, /型番/, /モデル番号/], value: values.manufacturerModelNumber?.trim() || null },
        { key: 'serial_number', patterns: [/シリアル番号/, /製造番号/], value: values.serialNumber?.trim() || null },
    ];
    for (const attribute of values.attributes) {
        const label = attribute.label.trim();
        const value = attribute.value.trim();
        if (!label || !value) continue;
        const escapedLabel = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        fields.push({ key: `attribute:${label}`, patterns: [new RegExp(escapedLabel)], value, exactOption: true });
    }

    const firstFieldMounted = (): boolean => controls().some((control) => titlePatterns.some((pattern) => pattern.test(labelFor(control))));
    if (!firstFieldMounted()) {
        const waitMs = 5000;
        await new Promise<void>((resolve) => {
            const observer = new MutationObserver(() => {
                if (firstFieldMounted()) { window.clearTimeout(timeoutId); observer.disconnect(); resolve(); }
            });
            const timeoutId = window.setTimeout(() => { observer.disconnect(); resolve(); }, waitMs);
            observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true });
            if (firstFieldMounted()) { window.clearTimeout(timeoutId); observer.disconnect(); resolve(); }
        });
    }

    const isNativeControl = (control: FormControl): control is HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement =>
        control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement;
    const controlValue = (control: FormControl): string => isNativeControl(control) ? control.value : control.innerText;
    const setValue = (control: FormControl, value: string): boolean => {
        if (!isNativeControl(control)) {
            if (!control.isContentEditable && control.getAttribute('role') !== 'textbox') return false;
            control.textContent = value;
            control.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: value }));
            control.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }
        const prototype = control instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype
            : control instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
        const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
        if (!setter) return false;
        setter.call(control, value);
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    };

    for (const field of fields) {
        if (field.value === null) continue;
        const matches = controls().filter((control) => field.patterns.some((pattern) => pattern.test(labelFor(control))));
        if (matches.length === 0) { result.missing.push(field.key); continue; }
        if (matches.length !== 1) { result.ambiguous.push(field.key); continue; }
        const control = matches[0];
        if (controlValue(control).trim() !== '') { result.existing.push(field.key); continue; }
        if (control instanceof HTMLSelectElement) {
            const option = Array.from(control.options).filter((candidate) => candidate.textContent?.trim() === field.value || candidate.value === field.value);
            if (option.length !== 1 || option[0].disabled || !setValue(control, option[0].value)) {
                result.missing.push(field.key);
                continue;
            }
            result.filled.push(field.key);
            continue;
        }
        if (!setValue(control, field.value)) { result.missing.push(field.key); continue; }
        result.filled.push(field.key);
    }

    const fileInputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[type="file"]'))
        .filter((input) => input.accept.split(',').some((type) => type.trim().startsWith('image/')) && !input.disabled);
    if (values.images.length > 0) {
        if (fileInputs.length !== 1) {
            (fileInputs.length === 0 ? result.missing : result.ambiguous).push('images');
        } else if (fileInputs[0].files?.length) {
            result.existing.push('images');
        } else {
            try {
                const transfer = new DataTransfer();
                for (const image of values.images) {
                    const [, encoded] = image.dataUrl.split(',', 2);
                    if (!encoded) continue;
                    const binary = atob(encoded);
                    const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
                    const blob = new Blob([bytes], { type: image.type });
                    if (!blob.type.startsWith('image/')) continue;
                    transfer.items.add(new File([blob], image.name, { type: image.type || blob.type }));
                }
                if (transfer.files.length === 0) result.missing.push('images');
                else {
                    const filesSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'files')?.set;
                    if (!filesSetter) result.missing.push('images');
                    else {
                        filesSetter.call(fileInputs[0], transfer.files);
                        if (fileInputs[0].files?.length !== transfer.files.length) result.missing.push('images');
                        else {
                            fileInputs[0].dispatchEvent(new Event('input', { bubbles: true }));
                            fileInputs[0].dispatchEvent(new Event('change', { bubbles: true }));
                            result.filled.push('images');
                        }
                    }
                }
            } catch { result.missing.push('images'); }
        }
    }

    const labels: Record<string, string> = { title: '商品名', description: '説明', price: '価格', category: 'カテゴリ', condition: '商品状態', shipping_method: '配送方法', shipping_fee: '送料', shipping_payer: '送料負担', sender_region: '発送元地域', dispatch_days: '発送までの日数', shipping_size: '配送サイズ', shipping_weight_grams: '配送重量', sale_format: '販売形式', listing_period_days: '掲載期間', return_policy: '返品方針', purchase_application: '購入申請', quantity: '数量', jan_ean: 'JAN/EAN', isbn: 'ISBN', manufacturer_model_number: '型番', serial_number: 'シリアル番号', images: '画像' };
    const displayLabel = (field: string): string => labels[field] ?? (field.startsWith('attribute:') ? field.slice('attribute:'.length) : field);
    const filled = result.filled.map(displayLabel);
    const unresolved = [...result.missing, ...result.ambiguous].map(displayLabel);
    const preserved = result.existing.map(displayLabel);
    const message = [
        filled.length ? `${filled.join('・')}を入力しました。` : '入力できる項目はありませんでした。',
        values.imageWarning ?? '',
        preserved.length ? `入力済みのため変更していません: ${preserved.join('・')}。` : '',
        unresolved.length ? `未入力（欄がない・複数候補・候補が一致しない）: ${unresolved.join('・')}。` : '',
        '入力内容を確認してください。出品確定は行っていません。',
    ].filter(Boolean).join(' ');
    const noticeHost = document.createElement('div');
    noticeHost.setAttribute('aria-live', 'polite');
    noticeHost.style.position = 'fixed';
    noticeHost.style.inset = 'auto 16px 16px auto';
    noticeHost.style.zIndex = '2147483647';
    const noticeRoot = noticeHost.attachShadow({ mode: 'closed' });
    const notice = document.createElement('div');
    notice.setAttribute('role', 'status');
    notice.textContent = message;
    notice.style.cssText = 'max-width: 420px; padding: 12px 16px; border-radius: 8px; background: #0f172a; color: #fff; border: 2px solid #22d3ee; box-shadow: 0 4px 16px #0008; font: 600 14px/1.5 system-ui, sans-serif;';
    noticeRoot.append(notice);
    document.documentElement.append(noticeHost);
    window.setTimeout(() => noticeHost.remove(), 10000);
    return values.imageWarning ? { ...result, imageWarning: values.imageWarning } : result;
}
