/**
 * Alfa Commerce: item field reload (product page AND catalog cards).
 *
 * ONE delegated handler for both surfaces (like add-to-cart / recalculate). When
 * a buyer changes a field control the item is re-fetched server-side and swapped
 * in place. The control declares, via data-item-reload, WHAT must refresh:
 *   - "item"  -> replace the whole [data-item-id] container (a variant change:
 *               other fields' availability + price + data + media all shift).
 *   - "price" -> replace only its [data-item-prices] (an option change: price only).
 *
 * The enclosing [data-item-form] carries the fetch URL (data-action) and the
 * inputs (sel[fieldId] for combinations, opt[fieldId] for options). The same
 * [data-item-id] container exists on the product page (.item-container) and on a
 * card (article.list-item), so one swap path serves both. On the single-item
 * page the selection is mirrored to the address bar.
 *
 * Handlers are delegated on document, so they survive every swap.
 */
(function () {
    'use strict';

    var controllers = {}; // item id -> AbortController (one in-flight fetch per item)

    // Same-selection cache (toggling a selection back and forth on the same item):
    // instant swap, no request. Keyed by fetch URL (id + selection). Short TTL so
    // stock/availability stays fresh; LRU cap on entries.
    var CACHE_TTL = 60000;
    var CACHE_MAX = 30;
    var cache = new Map(); // url -> { html, ts }

    function cacheGet(key) {
        var entry = cache.get(key);

        if (!entry) {
            return null;
        }

        if (Date.now() - entry.ts > CACHE_TTL) {
            cache.delete(key);

            return null;
        }

        cache.delete(key);
        cache.set(key, entry); // LRU touch

        return entry.html;
    }

    function cacheSet(key, html) {
        cache.set(key, { html: html, ts: Date.now() });

        if (cache.size > CACHE_MAX) {
            cache.delete(cache.keys().next().value);
        }
    }

    /**
     * Collect the form's active field selection: sel[fieldId] (combinations) and
     * opt[fieldId] (options), both marked with data-item-reload.
     */
    function selectionQuery(form) {
        var params = new URLSearchParams();

        form.querySelectorAll('[data-item-reload]').forEach(function (el) {
            if (el.disabled) {
                return;
            }

            if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) {
                return;
            }

            var value = el.value;

            if (!value || value === '0') {
                return;
            }

            params.append(el.name, value);
        });

        return params;
    }

    /**
     * Mirror the selection in the address bar, single-item page only (a list of
     * cards must not rewrite the URL for one card).
     */
    function syncAddressBar(node, query) {
        var app = node.closest('#alfa-app');

        if (!app || app.dataset.view !== 'item') {
            return;
        }

        var params = new URLSearchParams(location.search);

        Array.from(params.keys()).forEach(function (key) {
            if (key.indexOf('sel[') === 0 || key.indexOf('opt[') === 0 || key.indexOf('conf[') === 0) {
                params.delete(key);
            }
        });

        query.forEach(function (value, key) {
            // Plugin configuration payloads (conf[]) NEVER ride the address bar:
            // they are per-render state, they grow, and stacking one per reprice
            // once overflowed the server's URI limit (HTTP 414). They still ride
            // the reload fetch + the add-to-cart POST.
            if (key.indexOf('conf[') === 0) {
                return;
            }

            params.append(key, value);
        });

        // Human-readable address: ?opt[9]=24, not opt%5B9%5D=24. Brackets are
        // legal in a query string, but we relax the encoding ONLY inside the
        // KEYS (whose shape we control: sel[...]/opt[...]); every VALUE stays
        // fully percent-encoded, so arbitrary content can never leak through.
        var parts = [];

        params.forEach(function (value, key) {
            parts.push(
                encodeURIComponent(key).replace(/%5B/gi, '[').replace(/%5D/gi, ']')
                + '=' + encodeURIComponent(value),
            );
        });

        var merged = parts.join('&');
        history.replaceState(null, '', merged ? location.pathname + '?' + merged : location.pathname);
    }

    /**
     * The gallery (GLightbox + Keen-slider) binds to specific nodes, so a full
     * container swap leaves it on detached DOM. item.js exposes alfaInitItemMedia()
     * to rebind; it only exists on the product page (cards have no gallery).
     */
    function reinitMedia() {
        if (typeof window.alfaInitItemMedia === 'function') {
            window.alfaInitItemMedia();
        }
    }

    /**
     * The framework's STANDARD plugin hook. After every swap we announce it on the
     * live [data-item-id] node (bubbling) so a field plugin can own richer behavior
     * on its OWN DOM without touching the framework, e.g. a custom widget that must
     * re-mount on the fresh container after a variant swap, or react to a reprice.
     * detail = { itemId, scope: 'item'|'price' }.
     */
    function notifyUpdated(node, scope) {
        node.dispatchEvent(new CustomEvent('alfa:item:updated', {
            bubbles: true,
            detail: { itemId: node.dataset.itemId, scope: scope }
        }));
    }

    /**
     * What identifies the focused control inside a container about to be replaced,
     * or null when focus is somewhere else. The server re-renders the same controls,
     * so a name (plus the value, for a radio or checkbox) or a data-action finds the
     * one counterpart in the fresh container.
     */
    function focusedControl(container) {
        var active = document.activeElement;

        if (!active || active === container || !container.contains(active)) {
            return null;
        }

        return {
            name: active.getAttribute('name') || '',
            value: (active.type === 'radio' || active.type === 'checkbox') ? active.value : null,
            action: active.getAttribute('data-action') || ''
        };
    }

    /**
     * Give keyboard focus back after a whole-container swap, which detaches the
     * control the shopper was on and drops focus to the page body. Only ever to the
     * counterpart of that same control, and only when it can take focus; never to
     * a different one, so focus returns to where the shopper already was or not at all.
     */
    function restoreFocus(fresh, focused) {
        if (!focused || (!focused.name && !focused.action)) {
            return;
        }

        var candidates = focused.name
            ? fresh.querySelectorAll('[name="' + CSS.escape(focused.name) + '"]')
            : fresh.querySelectorAll('[data-action="' + CSS.escape(focused.action) + '"]');

        for (var i = 0; i < candidates.length; i++) {
            if (focused.value === null || candidates[i].value === focused.value) {
                if (!candidates[i].disabled) {
                    candidates[i].focus({ preventScroll: true });
                }

                return;
            }
        }
    }

    /**
     * Apply a fetched (or cached) item HTML to the page per the reload scope:
     * 'price' swaps only [data-item-prices], 'item' swaps the whole container.
     * Returns the live (in-DOM) node after the swap.
     */
    function applySwap(html, container, scope, query) {
        var itemId = container.dataset.itemId;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.querySelector('[data-item-id="' + itemId + '"]') || doc.querySelector('[data-item-id]');

        if (!fresh) {
            return container;
        }

        if (scope === 'price') {
            var freshPrice = fresh.querySelector('[data-item-prices]');
            var currentPrice = container.querySelector('[data-item-prices]');

            if (freshPrice && currentPrice) {
                currentPrice.replaceWith(freshPrice);
            }

            syncAddressBar(container, query);
            notifyUpdated(container, scope);

            return container;
        }

        // Whole-container swap: the delegated handlers rebind for free. Focus does
        // not: it is read before the control is detached and handed back after.
        var focused = focusedControl(container);

        container.replaceWith(fresh);
        reinitMedia();
        restoreFocus(fresh, focused);
        syncAddressBar(fresh, query);
        notifyUpdated(fresh, scope);

        return fresh;
    }

    /**
     * The reload address resolved against this page: its absolute URL while it stays on
     * this origin, otherwise null. The markup fetched from it is swapped into the page,
     * so whatever address a form carries, only this site's own is ever fetched.
     */
    function sameOriginUrl(route) {
        let target;

        try {
            target = new URL(route, window.location.href);
        } catch (error) {
            return null;
        }

        return target.origin === window.location.origin ? target.href : null;
    }

    /**
     * Re-fetch (or reuse the cache for) the item's current selection and swap.
     */
    async function reload(control) {
        var form = control.closest('[data-item-form]');
        var container = control.closest('[data-item-id]');

        if (!form || !container || !form.dataset.action) {
            return;
        }

        var itemId = container.dataset.itemId;
        var scope = control.dataset.itemReload === 'price' ? 'price' : 'item';
        var query = selectionQuery(form);
        var url = form.dataset.action + (form.dataset.action.indexOf('?') === -1 ? '?' : '&') + query.toString();

        // An address on another origin is never fetched and nothing is swapped.
        var target = sameOriginUrl(url);

        if (target === null) {
            console.error('[AlfaItemReload] the reload address (data-action) is not on this site; nothing was fetched');

            return;
        }

        // Cache hit: swap instantly, no request.
        var cachedHtml = cacheGet(target);

        if (cachedHtml !== null) {
            applySwap(cachedHtml, container, scope, query);

            return;
        }

        if (controllers[itemId]) {
            controllers[itemId].abort();
        }

        controllers[itemId] = new AbortController();
        container.classList.add('is-loading');

        try {
            var response = await fetch(target, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controllers[itemId].signal
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var html = await response.text();
            cacheSet(target, html);
            applySwap(html, container, scope, query);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('[AlfaItemReload] failed:', error);
            }
        } finally {
            // After an 'item' swap the original container is detached (the fresh
            // node carries no is-loading); only a surviving container needs it cleared.
            if (container.isConnected) {
                container.classList.remove('is-loading');
            }
        }
    }

    document.addEventListener('change', function (event) {
        var control = event.target.closest('[data-item-reload]');

        if (control) {
            reload(control);
            gateRequired(control.closest('[data-item-id]'));
        }
    });

    /**
     * REQUIRED choice fields gate add-to-cart live: the button carries the
     * required field ids; a field counts as answered when a sel[] or opt[]
     * control of it holds a value. (Payload fields are enforced server-side.)
     */
    function gateRequired(container) {
        if (!container) {
            return;
        }

        var btn = container.querySelector('[data-action="cart-add"][data-required-fields]');

        if (!btn) {
            return;
        }

        var ids = (btn.dataset.requiredFields || '').split(',').filter(Boolean);
        var payloadIds = (btn.dataset.requiredPayload || '').split(',').filter(Boolean);

        if (!ids.length && !payloadIds.length) {
            return;
        }

        var missingIds = ids.filter(function (fid) {
            var named = container.querySelectorAll(
                '[name="sel[' + fid + ']"], [name="opt[' + fid + ']"]'
            );

            return !Array.prototype.some.call(named, function (el) {
                if (el.type === 'radio' || el.type === 'checkbox') {
                    return el.checked && el.value !== '' && el.value !== '0';
                }

                return el.value !== '' && el.value !== '0';
            });
        });
        var missing = missingIds.length > 0;

        // Payload fields (e.g. the configurator): their full composition rides
        // a hidden input; empty/blank JSON means "not configured yet".
        var missingPayload = payloadIds.filter(function (fid) {
            var inp = container.querySelector('[name="jform[itemfields_payload][' + fid + ']"]');
            var v = inp ? inp.value.trim() : '';

            if (v === '' || v === '{}' || v === 'null') {
                return true;
            }

            try {
                var data = JSON.parse(v);
                var imgs = data.images || {};
                var texts = data.texts || {};
                var hasImg = Object.keys(imgs).some(function (kk) { return imgs[kk]; });
                var hasTxt = Object.keys(texts).some(function (kk) { return (texts[kk].value || '').trim() !== ''; });

                return !hasImg && !hasTxt;
            } catch (e) {
                return false; // unknown payload shape: leave it to the server
            }
        });
        missingIds = missingIds.concat(missingPayload);
        missing = missingIds.length > 0;

        // The required-fields gate NEVER overrides the stock gate: the server
        // marks an out-of-stock / unsalable unit with data-stock-blocked, and a
        // button blocked for stock stays disabled no matter how complete the
        // selection is. Without this OR, satisfying the required fields re-enabled
        // a button the server had disabled for stock.
        var stockBlocked = btn.dataset.stockBlocked === '1';

        btn.disabled = missing || stockBlocked;
        btn.title = missing ? (btn.dataset.requiredHint || '') : '';

        // The visible hint (mobile has no hover): list what is STILL missing.
        var box = container.querySelector('[data-required-hint-box]');

        if (box) {
            var labels = {};

            try { labels = JSON.parse(btn.dataset.requiredLabels || '{}'); } catch (e) { labels = {}; }

            var names = missingIds.map(function (fid) { return labels[fid] || fid; });
            box.hidden = !names.length;
            box.textContent = names.length
                ? (btn.dataset.requiredHintPrefix || 'Choose first: ') + names.join(', ')
                : '';
        }
    }

    // First paint: judge the initial state too (a saved payload may already
    // satisfy a required configurator).
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-item-id]').forEach(gateRequired);
    });

    // The field form is JS-driven (reload here, add-to-cart elsewhere); never let
    // an Enter keypress submit it as a full page load.
    document.addEventListener('submit', function (event) {
        if (event.target.matches('[data-item-form]')) {
            event.preventDefault();
        }
    });

    // Inspect in console: alfaItemReloadCache.size
    window.alfaItemReloadCache = cache;
})();
