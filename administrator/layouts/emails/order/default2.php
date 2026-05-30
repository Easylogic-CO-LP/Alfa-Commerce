<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * ─────────────────────────────────────────────────────────────────
 *  Order-status notification — minimal HTML email layout
 * ─────────────────────────────────────────────────────────────────
 *
 *  Joomla layout (id: emails.order.default). Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/order/default.php
 *
 *  Add sibling order-email variants as emails/order/<name>.php (id
 *  emails.order.<name>); they appear in the OrderStatusEmailLayout picker.
 *
 *  Helper API (extracted from $displayData at the top):
 *    $position($name)        — admin-fillable content slot. Calling it
 *                              here = a new editor on the form (discovered
 *                              via a collector render). Tokens inside the
 *                              content (e.g. {order_items}) resolve later.
 *    $hasPosition($name)     — true when that slot has content (guard
 *                              wrappers, like Joomla's countModules()).
 *    $render($id, $data?)    — render a sub-layout, e.g.
 *                              $render('emails.partials.order_items').
 *    $order, $items, $status, $tokens, $recipient, $langTag — raw data.
 *
 *  Variable substitution is central: OrderEmailHelper runs ONE pass over
 *  the whole composed output, replacing {{SITE_NAME}} / {{SITE_URL}} and
 *  every {token}. So emit raw text with variables — they resolve after.
 *
 *  Intentionally generic — system fonts only, grayscale palette, thin
 *  hairlines, no accent colour. Designed to look at home next to any
 *  shop's brand. Sites that want personality drop an override copy of
 *  this file and re-skin to taste; everything they need to substitute
 *  (markers + classes inside .email__body) is preserved across the
 *  override boundary.
 *
 *  Email-client notes:
 *
 *  • Outer scaffold is table-based — Outlook desktop renders with the
 *    Word HTML engine, which ignores flex/grid/most modern CSS.
 *  • Every visible element has inline `style="…"` because Gmail strips
 *    the <head> <style> block on quoted-reply renders. The block at
 *    the top is best-effort progressive enhancement (dark mode, media
 *    queries, and admin-authored-body typography).
 *  • System font stack — no web fonts. Most clients strip them, and
 *    "neutral" is the point here: stay out of the way of the shop's
 *    own typography on landing pages.
 */

defined('_JEXEC') or die;

/** @var array $displayData */
extract($displayData);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{SITE_NAME}}</title>

    <!--[if mso]>
    <noscript>
        <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
    </noscript>
    <![endif]-->

    <style>
        :root {
            --email-page: #f3f4f6;
            --email-card: #ffffff;
            --email-ink: #111827;
            --email-muted: #6b7280;
            --email-rule: #e5e7eb;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --email-page: #0a0a0a;
                --email-card: #121212;
                --email-ink: #e5e7eb;
                --email-muted: #9ca3af;
                --email-rule: #1f2937;
            }
        }

        body { margin: 0; padding: 0; width: 100% !important; background: var(--email-page); }

        /* Body-content typography — applies inside .email__body so any
           HTML the admin pastes into a position gets sensible defaults. */
        .email__body p { margin: 0 0 1em 0; font-size: 15px; line-height: 1.65; color: var(--email-ink); }
        .email__body p:last-child { margin-bottom: 0; }
        .email__body h1,
        .email__body h2,
        .email__body h3 { font-weight: 600; letter-spacing: -0.01em; margin: 1.5em 0 0.5em 0; color: var(--email-ink); }
        .email__body h1 { font-size: 22px; line-height: 1.25; }
        .email__body h2 { font-size: 18px; line-height: 1.3; }
        .email__body h3 { font-size: 15px; line-height: 1.35; text-transform: uppercase; letter-spacing: 0.06em; color: var(--email-muted); }
        .email__body a { color: var(--email-ink); text-decoration: underline; text-underline-offset: 2px; }
        .email__body ul,
        .email__body ol { padding-left: 1.1em; margin: 0 0 1em 0; }
        .email__body li { margin-bottom: 0.4em; line-height: 1.55; color: var(--email-ink); }
        .email__body table { width: 100%; border-collapse: collapse; margin: 1em 0; font-size: 14px; }
        .email__body th,
        .email__body td { padding: 9px 10px; text-align: left; border-bottom: 1px solid var(--email-rule); color: var(--email-ink); }
        .email__body th { font-weight: 500; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--email-muted); border-bottom: 1px solid var(--email-ink); }
        .email__body hr { border: 0; border-top: 1px solid var(--email-rule); margin: 1.8em 0; }
        .email__body strong { color: var(--email-ink); }
        .email__body em { font-style: italic; color: var(--email-muted); }
        .email__body img { max-width: 100%; height: auto; display: block; }

        .email__cta {
            display: inline-block;
            border: 1px solid var(--email-ink);
            background: var(--email-card);
            color: var(--email-ink) !important;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0.04em;
            padding: 11px 22px;
            text-decoration: none !important;
        }

        @media only screen and (max-width: 600px) {
            .email__shell { width: 100% !important; }
            .email__pad { padding-left: 24px !important; padding-right: 24px !important; }
            .email__masthead-title { font-size: 22px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#f3f4f6;color:#111827;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">

    <?php // Preheader — the hidden inbox-preview snippet email clients show
          // next to the subject. Site name; structural, not an admin slot. ?>
    <div style="display:none;font-size:1px;color:#f3f4f6;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {{SITE_NAME}}
    </div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:40px 16px;">

                <table role="presentation" class="email__shell" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;">

                    <!-- ─── Masthead ─── -->
                    <tr>
                        <td class="email__pad" align="left" style="padding:36px 44px 4px 44px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
                            <h1 class="email__masthead-title" style="margin:0;padding:0;font-weight:600;font-size:24px;line-height:1.2;letter-spacing:-0.01em;color:#111827;">
                                {{SITE_NAME}}
                            </h1>
                        </td>
                    </tr>

                    <!-- Hairline under masthead -->
                    <tr>
                        <td class="email__pad" style="padding:18px 44px 0 44px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr><td style="border-top:1px solid #e5e7eb;line-height:0;font-size:0;">&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr>

                    <?php // ─── Positions ───
                          // Body content the admin authors. Order:
                          //   header → intro → [items/totals] → outro → legal
                          // header + intro sit above the order table; outro
                          // below it; legal is the muted fine-print footer. ?>
                    <?php if ($hasPosition('header')) : ?>
                    <tr>
                        <td class="email__pad email__body" style="padding:24px 44px 0 44px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#111827;">
                            <?php echo $position('header'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($hasPosition('intro')) : ?>
                    <tr>
                        <td class="email__pad email__body" style="padding:12px 44px 0 44px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#111827;">
                            <?php echo $position('intro'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php // Order items + totals — STRUCTURE owned by this layout.
                          // The layout names the partial + args; swap the id or
                          // override the partial to restyle. Skipped for itemless
                          // orders. The outro position follows the table. ?>
                    <?php if (!empty($items)) : ?>
                    <tr>
                        <td class="email__pad" style="padding:18px 44px 0 44px;">
                            <?php echo $render('emails.partials.order_items', ['items' => $items]); ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="email__pad" style="padding:6px 44px 0 44px;">
                            <?php echo $render('emails.partials.order_totals', $totals); ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($hasPosition('outro')) : ?>
                    <tr>
                        <td class="email__pad email__body" style="padding:20px 44px 0 44px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#111827;">
                            <?php echo $position('outro'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <!-- Footer rule -->
                    <tr>
                        <td class="email__pad" style="padding:0 44px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr><td style="border-top:1px solid #e5e7eb;line-height:0;font-size:0;">&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr>

                    <!-- ─── Standing legal / fine-print position ─── -->
                    <?php if ($hasPosition('legal')) : ?>
                    <tr>
                        <td class="email__pad email__body" align="left" style="padding:20px 44px 32px 44px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.55;color:#6b7280;">
                            <?php echo $position('legal'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                </table>

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;">
                    <tr><td style="height:20px;line-height:20px;font-size:0;">&nbsp;</td></tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>
