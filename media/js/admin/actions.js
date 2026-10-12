/**
 * Alfa: plugin action buttons (admin).
 *
 * Every plugin action (payments, shipments, item fields) opens its own page,
 * view=action, in a modal iframe via Alfa.openAction(). The action runs once
 * there and either renders its layout (editor, form, logs) or a result notice
 * that posts {close, reload} back here. A page registers Alfa.actionReload to
 * control how it refreshes (the orders list swaps rows in place; others reload).
 *
 * Buttons inside an iframe popup hand off to the top window so the modal is
 * never nested.
 *
 * @package     Alfa.Component
 * @subpackage  Administrator.JavaScript
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.0.0
 */

window.Alfa = window.Alfa || {};


//  LOADING OVERLAY: Full-page spinner for slow actions

/**
 * Show a full-page loading overlay with a Bootstrap spinner.
 * Use for actions that trigger a page reload (so the admin sees
 * feedback before the page goes blank during the reload).
 */
Alfa.showLoading = function() {
    var ov = document.getElementById('alfa-loading-overlay');

    if (!ov) {
        ov = document.createElement('div');
        ov.id = 'alfa-loading-overlay';
        ov.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;'
            + 'background:rgba(0,0,0,.5);display:flex;align-items:center;'
            + 'justify-content:center;z-index:9999';
        ov.innerHTML = '<div class="spinner-border text-light" style="width:3rem;height:3rem"></div>';
        document.body.appendChild(ov);
    }

    ov.style.display = 'flex';
};

/**
 * Hide the full-page loading overlay.
 */
Alfa.hideLoading = function() {
    var ov = document.getElementById('alfa-loading-overlay');
    if (ov) ov.style.display = 'none';
};

//  TOOLTIP INIT: For action buttons in the edit view

/**
 * Initialize Bootstrap tooltips on [data-bs-toggle="tooltip"] elements.
 *
 * Called on DOMContentLoaded. Only needed in the edit view; the list
 * view uses its own alfa-tip / data-tip tooltip system.
 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});

//  PAGE ACTIONS: open an action's own page in an independent iframe
//
//  The action page (tmpl=component) is a fully independent document, so it can
//  load its own scripts/CSS (anything from a plain panel to a 3D editor), and
//  it tells us to close via postMessage.

// Open a plugin action as a standalone page (view=action) in the modal iframe.
// The action runs ONCE inside that view (no AJAX pre-dispatch), then the page
// either shows its layout (editor, form, logs) or a result notice that posts
// {close, reload} back here. One builder for every group.
//
// opts: { context, id, action, data, title }
Alfa.openAction = function (opts) {
    opts = opts || {};

    // Action buttons can sit inside an iframe popup (e.g. the payment/shipment
    // edit popup). Never nest a modal; hand off to the top window to open it.
    if (window.self !== window.top && window.parent) {
        window.parent.postMessage({ messageType: 'alfa:action-open', opts: opts }, window.location.origin);
        return false;
    }

    var groups = { itemfield: 'alfa-item-fields', payment: 'alfa-payments', shipment: 'alfa-shipments' };
    var idParam = { itemfield: 'field_id', payment: 'payment_id', shipment: 'shipment_id' };
    var group = groups[opts.context];

    if (!group) {
        console.error('[Alfa] Unknown action context:', opts.context);
        return false;
    }

    var data = opts.data || {};
    var token = Joomla.getOptions('csrf.token');

    // A state-changing action is SUBMITTED. A URL can be refreshed, shared or
    // walked back onto with the browser's history; a POST carrying a one-time
    // key cannot re-run the same money operation.
    if (opts.mutates) {
        var fields = {
            option: 'com_alfa',
            view: 'action',
            tmpl: 'component',
            group: group,
            action: opts.action,
            once: Alfa.onceKey()
        };
        fields[idParam[opts.context]] = opts.id;
        Object.keys(data).forEach(function (k) { fields[k] = data[k]; });
        if (token) { fields[token] = 1; }

        Alfa.openActionFrame(null, opts.title || '', fields);
        return false;
    }

    var url = 'index.php?option=com_alfa&view=action&tmpl=component'
        + '&group=' + encodeURIComponent(group)
        + '&action=' + encodeURIComponent(opts.action)
        + '&' + idParam[opts.context] + '=' + encodeURIComponent(opts.id);

    Object.keys(data).forEach(function (k) {
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    });

    if (token) { url += '&' + token + '=1'; }

    Alfa.openActionFrame(url, opts.title || '');
    return false;
};

// One-time key for a state-changing submission. The server consumes it, so a
// resubmitted form is refused even though it carries a valid CSRF token.
Alfa.onceKey = function () {
    if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }

    return 'once-' + Date.now() + '-' + Math.random().toString(36).slice(2);
};

/**
 * Strip the admin navigation inside the frame, keep the toolbar.
 *
 * The frame loads real admin screens. `tmpl=component` used to be appended to
 * them, which is precisely what removed the toolbar: Joomla's component template
 * has no `toolbar` module position, so Save and New simply did not exist in the
 * document. Now the page arrives whole and this marks it for frame.css, which
 * hides the header, the sidebar and the status bar and pins the toolbar.
 *
 * Runs on every load event, because saving inside the frame navigates it to a
 * fresh document that knows nothing about the previous dressing.
 *
 * @param {HTMLIFrameElement} frame The modal's iframe.
 */
Alfa.dressActionFrame = function (frame) {
    var doc;

    try {
        doc = frame.contentDocument;
    } catch (e) {
        return;                                     // cross-origin: not ours to dress
    }

    if (!doc || !doc.documentElement || !doc.head) {
        return;
    }

    doc.documentElement.setAttribute('data-alfa-frame', '1');

    if (doc.querySelector('link[data-alfa-frame-css]')) {
        return;
    }

    // Borrow the cache-busting query from a stylesheet the host page already
    // carries, so the framed copy expires exactly when every other asset does.
    var ref = document.querySelector('link[href*="/media/com_alfa/css/admin/"]');
    var query = '';

    if (ref && ref.href.indexOf('?') !== -1) {
        query = ref.href.slice(ref.href.indexOf('?'));
    }

    var paths = window.Joomla && Joomla.getOptions ? Joomla.getOptions('system.paths') : null;
    var base = paths && paths.rootFull ? paths.rootFull : '/';
    var link = doc.createElement('link');

    link.rel = 'stylesheet';
    link.href = base + 'media/com_alfa/css/admin/frame.css' + query;
    link.setAttribute('data-alfa-frame-css', '1');
    doc.head.appendChild(link);
};

Alfa.openActionFrame = function (url, title, fields) {
    var modalEl = document.getElementById('alfa-action-frame-modal');

    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.id = 'alfa-action-frame-modal';
        modalEl.className = 'modal fade';
        modalEl.tabIndex = -1;
        modalEl.innerHTML =
            '<div class="modal-dialog modal-xl modal-dialog-centered">'
            + '<div class="modal-content">'
            +   '<div class="modal-header">'
            +     '<h5 class="modal-title"></h5>'
            +     '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
            +   '</div>'
            +   '<div class="modal-body p-0" style="position:relative">'
            +     '<alfa-core-loader class="alfa-action-frame-loader" inline size="140" hidden '
            +            'style="position:absolute;inset:0;pointer-events:none"></alfa-core-loader>'
            +     '<iframe class="alfa-action-frame" src="about:blank" title="" '
            +            'style="width:100%;height:72vh;border:0;display:block"></iframe>'
            +   '</div>'
            + '</div>'
            + '</div>';
        document.body.appendChild(modalEl);
    }

    modalEl.querySelector('.modal-title').textContent = title || '';

    var frame = modalEl.querySelector('.alfa-action-frame');
    frame.name = frame.name || 'alfa-action-frame';

    // The modal opens instantly but the frame is still fetching; without this the
    // merchant stares at an empty white box and clicks again. Bound once; the POST
    // path blanks the frame first, and that about:blank load must NOT count as
    // arrival or the loader would vanish before the real page starts.
    var frameLoader = modalEl.querySelector('.alfa-action-frame-loader');

    if (frameLoader && !frame.dataset.alfaLoaderBound) {
        frame.dataset.alfaLoaderBound = '1';
        frame.addEventListener('load', function () {
            var href = '';

            try {
                href = frame.contentWindow.location.href;
            } catch (e) {
                href = '';                                  // cross-origin: it arrived
            }

            if (href !== 'about:blank') {
                frameLoader.hidden = true;
            }

            Alfa.dressActionFrame(frame);
        });
    }

    if (frameLoader) {
        frameLoader.hidden = false;
    }

    if (fields) {
        // POST into the frame: same modal, but the action is submitted rather
        // than navigated to, so nothing about it is replayable from history.
        //
        // THE FORM LIVES IN THE FRAME'S OWN DOCUMENT. A form on this page aimed
        // at the frame by name found no window of that name once the frame had
        // shown a page: the browsing context's name does not follow the
        // element's attribute through its navigations, and a target nobody
        // answers to means a new window, which a submit() with no click
        // behind it may not open. The POST was dropped without a word and the
        // loader spun for ever. Inside the frame there is no name to match:
        // the form submits into the document it lives in. The action URL is
        // absolute because about:blank has no base of its own to resolve one.
        var action = new URL('index.php', document.baseURI).href;

        var submitInFrame = function () {
            var doc;

            try {
                doc = frame.contentDocument;
            } catch (e) {
                doc = null;                                 // cross-origin: cannot reach in
            }

            if (!doc || !doc.body) {
                return false;
            }

            var form = doc.createElement('form');
            form.method = 'POST';
            form.action = action;
            form.style.display = 'none';

            Object.keys(fields).forEach(function (k) {
                var input = doc.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = fields[k];
                form.appendChild(input);
            });

            doc.body.appendChild(form);
            form.submit();

            return true;
        };

        (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();

        // A frame still holding a page of this site can post from it directly;
        // one holding something else is blanked first and posts once the blank
        // document exists.
        if (!submitInFrame()) {
            frame.addEventListener('load', function onBlank() {
                frame.removeEventListener('load', onBlank);
                submitInFrame();
            });
            frame.src = 'about:blank';
        }

        return;
    }

    frame.src = url;

    (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
};

// A page registers how it wants to refresh after an action (e.g. the orders
// list swaps its rows in place; the item edit reloads its panel). Falls back
// to a full reload.
/**
 * Links marked `data-alfa-frame-link` open in the action frame instead of
 * navigating.
 *
 * A product with no item fields has to send the merchant to the item fields to
 * create one, but a plain link would throw away everything typed into the
 * product form first. The frame keeps the product exactly where it is, and since
 * the frame now loads whole admin screens, the item fields arrive with their own
 * toolbar. Delegated, so it also covers markup swapped in later.
 */
document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a[data-alfa-frame-link]') : null;

    if (!link || typeof Alfa.openActionFrame !== 'function') {
        return;                                   // no frame available: follow the href
    }

    event.preventDefault();
    Alfa.openActionFrame(link.getAttribute('href'), link.getAttribute('data-frame-title') || '');
});

Alfa.actionReload = null;

window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin) { return; }
    var msg = event.data || {};

    // A button inside an iframe popup asks the top window to open the action,
    // so the modal opens here instead of nested in the popup.
    if (msg.messageType === 'alfa:action-open') {
        Alfa.openAction(msg.opts);
        return;
    }

    // The action page posts back when it is done (saved / cancelled).
    if (msg.messageType !== 'alfa:action-frame') { return; }

    var modalEl = document.getElementById('alfa-action-frame-modal');
    if (modalEl) {
        var inst = bootstrap.Modal.getInstance(modalEl);
        if (inst) { inst.hide(); }
    }

    if (msg.reload) {
        if (typeof Alfa.actionReload === 'function') {
            Alfa.actionReload();
        } else if (typeof Alfa.reloadItemPage === 'function') {
            Alfa.reloadItemPage();
        } else {
            window.location.reload();
        }
    }
});
