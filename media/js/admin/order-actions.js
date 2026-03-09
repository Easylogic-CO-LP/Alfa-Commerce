/**
 * Alfa Order Management — Action Execution API
 *
 * Shared library for plugin action buttons.
 * Used by BOTH the order edit view AND the orders list view.
 *
 * Contexts:
 *
 *   Edit view (main page):
 *     Calls Alfa.executeAction() directly from button onclick attributes.
 *     No AlfaActionContext set → _handleNavigation does location.reload()
 *     or redirect.
 *
 *   Edit view (inside iframe modal):
 *     AlfaActionContext is set by edit_payment.php / edit_shipment.php.
 *     _handleNavigation sends postMessage to parent instead of reloading.
 *
 *   Orders list view:
 *     orders-list.js calls Alfa.executeAction() with an onSuccess callback.
 *     The callback reloads only the detail panel — not the full page.
 *     _handleNavigation is never called in this context.
 *
 * Key design:
 *   The optional 6th parameter `onSuccess` lets callers override what
 *   happens after a successful refresh/redirect result. When omitted,
 *   behavior is identical to the original (backward compatible).
 *
 * @package     Alfa.Component
 * @subpackage  Administrator.JavaScript
 * @version     4.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * @since  3.0.0
 */

window.Alfa = window.Alfa || {};

// ═══════════════════════════════════════════════════════════════════
//  CORE: ACTION EXECUTION
// ═══════════════════════════════════════════════════════════════════

/**
 * Execute a plugin action via AJAX.
 *
 * Handles the full lifecycle:
 *   1. CSRF token retrieval
 *   2. Button loading state (spinner)
 *   3. POST to execute{Context}Action endpoint
 *   4. Response parsing (text-first to survive PHP notices)
 *   5. Success: modal OR navigation via onSuccess callback OR _handleNavigation
 *   6. Error: Joomla message render
 *   7. Button state restore
 *
 * The optional `onSuccess` callback (6th parameter) is the extension
 * point for callers that need different post-action behavior.
 * When provided, it receives (result, action) and is responsible for
 * navigation/refresh. When omitted, _handleNavigation() is used.
 *
 * @param {string}        context        'payment' or 'shipment'
 * @param {number}        id             Entity PK
 * @param {string}        action         Action ID (e.g. 'mark_paid')
 * @param {HTMLElement}   button         Clicked button element
 * @param {object}        additionalData Optional extra data merged into body
 * @param {function|null} onSuccess      Optional post-success callback(result, action)
 *                                       Called instead of _handleNavigation when
 *                                       result.refresh or result.redirect is set.
 */
Alfa.executeAction = function(context, id, action, button, additionalData, onSuccess) {
    additionalData = additionalData || {};
    onSuccess      = onSuccess      || null;

    console.log('[Alfa] Executing:', context, id, action);

    // ── CSRF token ────────────────────────────────────────────────
    // Joomla.getOptions('csrf.token') is the reliable Joomla 5 way.
    // Never scrape the DOM for the token — the key name is random per session.
    var token = Joomla.getOptions('csrf.token');

    if (!token) {
        console.error('[Alfa] CSRF token missing');
        Joomla.renderMessages({ error: ['Security token not found. Reload the page.'] });
        return false;
    }

    // ── Build task URL ────────────────────────────────────────────
    var taskName = 'execute' + context.charAt(0).toUpperCase() + context.slice(1) + 'Action';
    var url      = 'index.php?option=com_alfa&task=order.' + taskName;

    // ── Request body ──────────────────────────────────────────────
    // Token goes in the JSON body (Joomla convention for JSON AJAX).
    var body        = { id: id, action: action, data: additionalData };
    body[token]     = '1';

    // ── Button: loading state ─────────────────────────────────────
    var originalHTML     = button.innerHTML;
    var originalDisabled = button.disabled;

    button.disabled  = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';

    // ── AJAX ──────────────────────────────────────────────────────
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(body)
    })
        // ── Read as text first ───────────────────────────────────
        // If PHP emits notices/warnings before the JSON, response.json()
        // would throw a SyntaxError. Reading text lets us handle gracefully.
        .then(function(response) {
            if (!response.ok) {
                return response.text().then(function(text) {
                    var msg = 'Server error (HTTP ' + response.status + ')';

                    try {
                        var parsed = JSON.parse(text);
                        if (parsed.message) msg = parsed.message;
                    } catch (e) {
                        if (text.indexOf('not allowed') !== -1 || text.indexOf('not found') !== -1) {
                            msg = 'Task "order.' + taskName + '" not found in controller.';
                        }
                    }

                    throw new Error(msg);
                });
            }

            return response.text();
        })
        .then(function(text) {
            // ── Parse JSON ───────────────────────────────────────
            var result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                console.error('[Alfa] Invalid JSON:', text.substring(0, 300));
                throw new Error('Invalid server response');
            }

            console.log('[Alfa] Result:', result);

            if (result.success) {
                // ── Success message ──────────────────────────────
                if (result.message) {
                    Joomla.renderMessages({ success: [result.message] });
                }

                if (result.refresh || result.redirect) {
                    // ── Navigation ───────────────────────────────
                    // Use caller's onSuccess if provided (e.g. list view
                    // reloads actions panel instead of full page).
                    // Fall back to _handleNavigation (edit view behavior).
                    if (typeof onSuccess === 'function') {
                        onSuccess(result, action);
                    } else {
                        Alfa._handleNavigation(result, action);
                    }

                    // Do NOT restore button — page is about to change.
                    return;

                } else if (result.html) {
                    // ── Modal response ───────────────────────────
                    // Plugin returned HTML to display in a popup.
                    // No navigation — modal IS the feedback.
                    Alfa.showActionModal(
                        result.modal_title || (context + ' — ' + action),
                        result.html
                    );

                    // Restore button — page stays, admin may act again
                    button.disabled  = originalDisabled;
                    button.innerHTML = originalHTML;

                } else {
                    // ── Simple success (message only) ────────────
                    button.disabled  = originalDisabled;
                    button.innerHTML = originalHTML;
                }

            } else {
                // ── Server-side error ────────────────────────────
                Joomla.renderMessages({ error: [result.message || 'Unknown error'] });
                button.disabled  = originalDisabled;
                button.innerHTML = originalHTML;
            }
        })
        .catch(function(error) {
            // ── Network / parse error ────────────────────────────
            console.error('[Alfa] Failed:', error);
            Joomla.renderMessages({ error: [error.message || 'Action failed'] });
            button.disabled  = originalDisabled;
            button.innerHTML = originalHTML;
        });

    return false;
};

// ═══════════════════════════════════════════════════════════════════
//  NAVIGATION — Refresh or redirect after successful action
// ═══════════════════════════════════════════════════════════════════

/**
 * Handle page refresh or redirect after a successful action.
 *
 * Called by executeAction() when no onSuccess callback is provided
 * (i.e. in the order edit view context).
 *
 * Three contexts:
 *
 *   Inside iframe modal (AlfaActionContext.iframe = true):
 *     Sends postMessage to parent window so it can close the iframe
 *     modal and reload the order page. The iframe itself must not
 *     reload — it would just reload the inner form, not the parent.
 *
 *   Main page with redirect URL:
 *     Navigates to result.redirect after a short delay (so the success
 *     message is visible before the page changes).
 *
 *   Main page without redirect (just refresh):
 *     Reloads the current page after a short delay.
 *
 * @param  {object}  result  The action result from the server
 * @param  {string}  action  The action ID that was executed
 *
 * @private
 */
Alfa._handleNavigation = function(result, action) {
    var ctx = window.AlfaActionContext;

    if (ctx && ctx.iframe && window.parent && window.parent !== window) {
        // ── Iframe context: notify parent ────────────────────
        // The parent window listens for this and handles close + reload.
        console.log('[Alfa] Iframe context — notifying parent');

        window.parent.postMessage({
            messageType: ctx.messageType,
            action:      action,
            paymentId:   ctx.entityId,   // used by edit_payments.php listener
            shipmentId:  ctx.entityId,   // used by edit_shipments.php listener
            shouldClose: true,
            shouldReload: true
        }, '*');

    } else if (result.redirect) {
        // ── Main page: redirect ──────────────────────────────
        setTimeout(function() {
            window.location.href = result.redirect;
        }, 800);

    } else {
        // ── Main page: reload ────────────────────────────────
        setTimeout(function() {
            location.reload();
        }, 800);
    }
};

// ═══════════════════════════════════════════════════════════════════
//  MODAL — Show plugin response HTML in a Bootstrap popup
// ═══════════════════════════════════════════════════════════════════

/**
 * Show action response HTML in a Bootstrap modal.
 *
 * Auto-creates the modal DOM element on first use — no PHP template
 * changes needed. The element is reused across calls; content is
 * replaced each time.
 *
 * Uses Bootstrap.Modal.getInstance() || new Bootstrap.Modal() so
 * the same instance is reused if the modal was shown before (avoids
 * duplicate backdrop stacking).
 *
 * Called from both the edit view (directly from buttons) and the
 * list view (via orders-list.js → Alfa.executeAction).
 *
 * @param  {string}  title  Modal header title
 * @param  {string}  html   Body HTML (rendered by PluginLayoutHelper)
 */
Alfa.showActionModal = function(title, html) {
    var modalEl = document.getElementById('alfa-action-modal');

    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.id       = 'alfa-action-modal';
        modalEl.className = 'modal fade';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');

        // Structure matches Bootstrap's expected modal layout exactly.
        // The modal-footer Close button is required for Bootstrap's
        // internal dismiss wiring (data-bs-dismiss="modal").
        modalEl.innerHTML =
            '<div class="modal-dialog modal-lg modal-dialog-centered">'
            + '<div class="modal-content">'
            +   '<div class="modal-header">'
            +     '<h5 class="modal-title"></h5>'
            +     '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
            +   '</div>'
            +   '<div class="modal-body"></div>'
            +   '<div class="modal-footer">'
            +     '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
            +           (Joomla.Text._('JCLOSE') || 'Close')
            +     '</button>'
            +   '</div>'
            + '</div>'
            + '</div>';

        document.body.appendChild(modalEl);
    }

    // Populate content
    modalEl.querySelector('.modal-title').textContent = title;
    modalEl.querySelector('.modal-body').innerHTML    = html;

    // Show — by the time a fetch .then() fires, Bootstrap is always
    // loaded. No retry loop needed (unlike DOMContentLoaded tooltips).
    var bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    bsModal.show();
};

// ═══════════════════════════════════════════════════════════════════
//  LOADING OVERLAY — Full-page spinner for slow actions
// ═══════════════════════════════════════════════════════════════════

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

// ═══════════════════════════════════════════════════════════════════
//  TOOLTIP INIT — For action buttons in the edit view
// ═══════════════════════════════════════════════════════════════════

/**
 * Initialize Bootstrap tooltips on [data-bs-toggle="tooltip"] elements.
 *
 * Called on DOMContentLoaded. Only needed in the edit view — the list
 * view uses its own alfa-tip / data-tip tooltip system.
 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});