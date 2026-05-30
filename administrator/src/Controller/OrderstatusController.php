<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderEmailHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * Orderstatus admin controller.
 *
 * @since  1.0.1
 */
class OrderstatusController extends FormController
{
    /**
     * View used by the cancel toolbar button.
     *
     * @var string
     */
    protected $view_list = 'orderstatuses';

    /**
     * Render the email this status would send and stream the HTML back
     * to the browser. Hit from an `<iframe>` inside the preview modal
     * on the edit screen.
     *
     * Query parameters:
     *   id         (int)    — orderstatus PK to preview.
     *   lang       (string) — language tag (e.g. en-GB) to render in.
     *                         Defaults to the application's current tag.
     *   recipient  (string) — 'customer' or 'admin'. Picks which
     *                         positions JSON to read.
     *
     * Behaviour:
     *   • Authorises via the standard com_alfa edit permission.
     *   • Picks the most recent published `#__alfa_orders` row as the
     *     sample for token resolution. Falls back to a synthetic stub
     *     when the table is empty (fresh install).
     *   • Outputs the rendered email HTML and exits so format=raw
     *     suppresses the Joomla chrome.
     *
     * @return void
     *
     * @since   1.0.4
     */
    public function previewEmail(): void
    {
        $app = $this->app;
        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.edit', 'com_alfa')) {
            $app->setHeader('status', '403 Forbidden', true);
            $app->sendHeaders();
            echo 'Forbidden';
            $app->close();
        }

        $input     = $app->getInput();
        $statusId  = (int) $input->getInt('id', 0);
        $langTag   = trim((string) $input->getString('lang', ''));
        $recipient = (string) $input->getCmd('recipient', 'customer');

        if (!in_array($recipient, ['customer', 'admin'], true)) {
            $recipient = 'customer';
        }
        if ($langTag === '') {
            $langTag = $app->getLanguage()->getTag();
        }
        if ($statusId <= 0) {
            $this->emitPreviewError(message: 'Missing or invalid status id.');
        }

        try {
            $html = OrderEmailHelper::previewForStatus(
                statusId:  $statusId,
                langTag:   $langTag,
                recipient: $recipient,
            );

            $app->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
            $app->sendHeaders();
            echo $html;
        } catch (Throwable $e) {
            Log::add(
                entry:    'OrderstatusController::previewEmail failed: ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.orderemail',
            );
            $this->emitPreviewError(message: $e->getMessage());
        }

        $app->close();
    }

    /**
     * Render the preview-modal shell page.
     *
     * Loaded inside the Bootstrap modal's iframe (via
     * HTMLHelper::_('bootstrap.renderModal', …)). The shell is a small
     * Joomla-themed page with one tab per (language × recipient) combo
     * and a single nested iframe whose `src` is swapped on tab change.
     * Each inner iframe URL hits previewEmail() to render the actual
     * email HTML.
     *
     * Output is a complete HTML document so the iframe doesn't depend
     * on the parent admin chrome — keeps the rendering predictable.
     *
     * @return void
     *
     * @since   1.0.4
     */
    public function previewShell(): void
    {
        $app  = $this->app;
        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.edit', 'com_alfa')) {
            $app->setHeader('status', '403 Forbidden', true);
            $app->sendHeaders();
            echo 'Forbidden';
            $app->close();
        }

        $statusId = (int) $app->getInput()->getInt('id', 0);

        if ($statusId <= 0) {
            $this->emitPreviewError(message: 'Missing or invalid status id.');
        }

        $languages = LanguageHelper::getLanguages('lang_code') ?: [];

        // Optional recipient scoping — the per-tab Preview button passes
        // &recipient=customer|admin so the modal shows only that side.
        // Omitted → both (the combined view).
        $only       = (string) $app->getInput()->getCmd('recipient', '');
        $recipients = in_array($only, ['customer', 'admin'], true) ? [$only] : ['customer', 'admin'];

        // Group by recipient — outer tabs (Customer / Admin) hold all
        // languages, with a dropdown inside each pane when more than
        // one language exists.
        $groups = [];
        foreach ($recipients as $recipient) {
            $entries = [];

            foreach ($languages as $langCode => $language) {
                $entries[] = [
                    'lang'  => (string) $langCode,
                    'label' => (string) ($language->title_native ?? $langCode),
                    'url'   => Route::_(
                        sprintf(
                            'index.php?option=com_alfa&task=orderstatus.previewEmail&format=raw&id=%d&recipient=%s&lang=%s',
                            $statusId,
                            urlencode($recipient),
                            urlencode((string) $langCode),
                        ),
                        false,
                    ),
                ];
            }

            $groups[$recipient] = [
                'key'       => $recipient,
                'label'     => Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS_' . strtoupper($recipient)),
                'languages' => $entries,
            ];
        }

        $app->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $app->sendHeaders();

        echo $this->renderShellHtml(
            groups:   $groups,
            statusId: $statusId,
        );

        $app->close();
    }

    /**
     * Render the inner shell HTML for the preview modal iframe.
     *
     * Outer tabs: one per recipient variant (Customer / Admin). Each
     * pane holds a language dropdown (only shown when 2+ languages
     * exist) and a single iframe whose src swaps on tab + dropdown
     * change. Saves horizontal space versus a flat tab-per-combo grid.
     *
     * Self-contained document; tiny vanilla JS handles tab and
     * dropdown switching. Atum CSS provides Bootstrap visuals.
     *
     * @param array<string, array> $groups   Map recipient → [key, label,
     *                                       languages[lang, label, url]].
     * @param int                  $statusId Status PK, used only for
     *                                       the document title.
     *
     * @return string Complete HTML page.
     *
     * @since   1.0.4
     */
    private function renderShellHtml(array $groups, int $statusId): string
    {
        $titleText = htmlspecialchars(
            Text::sprintf('COM_ALFA_ORDERSTATUS_PREVIEW_EMAIL_TITLE_WITH_ID', $statusId),
            ENT_COMPAT,
            'UTF-8',
        );

        // Empty-language guard — render a friendly message instead of
        // a chrome with no content.
        $hasAny = false;
        foreach ($groups as $g) {
            if (!empty($g['languages'])) {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny) {
            return '<!doctype html><html><body><p style="font-family:sans-serif;padding:24px;">No active languages.</p></body></html>';
        }

        $tabs  = '';
        $panes = '';
        $first = true;

        foreach ($groups as $recipient => $group) {
            $paneId = 'preview-pane-' . $recipient;
            $tabs  .= sprintf(
                '<li class="nav-item" role="presentation">'
                    . '<button type="button" class="nav-link%s" data-preview-target="#%s" role="tab">%s</button>'
                . '</li>',
                $first ? ' active' : '',
                htmlspecialchars($paneId, ENT_COMPAT, 'UTF-8'),
                htmlspecialchars((string) ($group['label'] ?? $recipient), ENT_COMPAT, 'UTF-8'),
            );

            $entries = $group['languages'] ?? [];
            $firstUrl = (string) ($entries[0]['url'] ?? '');

            $picker = '';
            if (count($entries) > 1) {
                $options = '';
                foreach ($entries as $i => $entry) {
                    $options .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        htmlspecialchars((string) $entry['url'], ENT_COMPAT, 'UTF-8'),
                        $i === 0 ? ' selected' : '',
                        htmlspecialchars((string) $entry['label'], ENT_COMPAT, 'UTF-8'),
                    );
                }

                $pickerLabel = htmlspecialchars(
                    Text::_('COM_ALFA_ORDEREMAIL_TEST_LBL_LANGUAGE'),
                    ENT_COMPAT,
                    'UTF-8',
                );
                $picker = sprintf(
                    '<div class="d-flex align-items-center gap-2 px-3 py-2 bg-white border-bottom flex-shrink-0">'
                        . '<label class="form-label text-uppercase fw-semibold small text-muted mb-0">%s</label>'
                        . '<select class="form-select form-select-sm" style="max-width:240px;" data-preview-lang>%s</select>'
                    . '</div>',
                    $pickerLabel,
                    $options,
                );
            }

            $panes .= sprintf(
                '<div class="flex-column flex-grow-1%s" id="%s" data-preview-pane style="min-height:0;">'
                    . '%s'
                    . '<div class="flex-grow-1 bg-light" style="min-height:0;"><iframe class="w-100 h-100 border-0 d-block" data-preview-frame src="%s"></iframe></div>'
                . '</div>',
                $first ? ' d-flex' : ' d-none',
                htmlspecialchars($paneId, ENT_COMPAT, 'UTF-8'),
                $picker,
                htmlspecialchars($firstUrl, ENT_COMPAT, 'UTF-8'),
            );

            $first = false;
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$titleText}</title>
    <link rel="stylesheet" href="/media/templates/administrator/atum/css/template.min.css">
    <style>
        html, body { margin: 0; height: 100%; }
        body { display: flex; flex-direction: column; background: #f3f4f6; }
        .nav-tabs { padding: 10px 14px 0 14px; background: #fff; flex-shrink: 0; }
    </style>
</head>
<body>
    <ul class="nav nav-tabs" role="tablist">{$tabs}</ul>
    {$panes}
    <script>
        (function () {
            'use strict';

            // Outer tab switching — toggle d-none / d-flex on panes.
            const tabs  = document.querySelectorAll('.nav-tabs .nav-link[data-preview-target]');
            const panes = document.querySelectorAll('[data-preview-pane]');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    const target = tab.getAttribute('data-preview-target');
                    tabs.forEach(function (t) { t.classList.remove('active'); });
                    panes.forEach(function (p) { p.classList.remove('d-flex'); p.classList.add('d-none'); });
                    tab.classList.add('active');
                    const targetPane = document.querySelector(target);
                    if (targetPane) { targetPane.classList.remove('d-none'); targetPane.classList.add('d-flex'); }
                });
            });

            // Per-pane language dropdown — swap iframe src.
            panes.forEach(function (pane) {
                const select = pane.querySelector('[data-preview-lang]');
                const frame  = pane.querySelector('[data-preview-frame]');
                if (!select || !frame) return;
                select.addEventListener('change', function () {
                    frame.src = select.value;
                });
            });
        })();
    </script>
</body>
</html>
HTML;
    }

    /**
     * Render the send-test-email form inside the modal iframe.
     *
     * GET endpoint. Outputs a small Joomla-themed page with a form:
     *   • Order picker (recent 25 orders)
     *   • Recipient variant (customer / admin)
     *   • Language picker
     *   • Destination email (pre-filled with current admin's email)
     *   • Submit posts to sendTestEmail() in the same iframe.
     *
     * @return void
     *
     * @since   1.0.4
     */
    public function sendTestForm(): void
    {
        $app  = $this->app;
        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.edit', 'com_alfa')) {
            $app->setHeader('status', '403 Forbidden', true);
            $app->sendHeaders();
            echo 'Forbidden';
            $app->close();
        }

        $statusId = (int) $app->getInput()->getInt('id', 0);

        if ($statusId <= 0) {
            $this->emitPreviewError(message: 'Missing or invalid status id.');
        }

        $app->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $app->sendHeaders();

        $recipient = (string) $app->getInput()->getCmd('recipient', '');
        $recipient = in_array($recipient, ['customer', 'admin'], true) ? $recipient : 'customer';
        $lang      = trim((string) $app->getInput()->getString('lang', ''));

        echo $this->renderSendTestFormHtml(statusId: $statusId, user: $user, recipient: $recipient, lang: $lang);

        $app->close();
    }

    /**
     * Process the send-test-email form submission.
     *
     * POST endpoint. Reads the form values, calls
     * OrderEmailHelper::sendTestForStatus, renders a result page in
     * the same iframe ("Sent" / error). The page has a "Send another"
     * link back to sendTestForm.
     *
     * @return void
     *
     * @since   1.0.4
     */
    public function sendTestEmail(): void
    {
        $app  = $this->app;
        $user = $app->getIdentity();

        if ($user === null || !$user->authorise('core.edit', 'com_alfa')) {
            $app->setHeader('status', '403 Forbidden', true);
            $app->sendHeaders();
            echo 'Forbidden';
            $app->close();
        }

        $input = $app->getInput();

        $statusId    = (int) $input->getInt('status_id', 0);
        $orderId     = (int) $input->getInt('order_id', 0);
        $langTag     = trim((string) $input->getString('lang', ''));
        $recipient   = (string) $input->getCmd('recipient', 'customer');
        $destination = trim((string) $input->getString('destination', ''));

        if ($langTag === '') {
            $langTag = $app->getLanguage()->getTag();
        }

        $error = null;

        try {
            OrderEmailHelper::sendTestForStatus(
                orderId:          $orderId,
                statusId:         $statusId,
                langTag:          $langTag,
                recipient:        $recipient,
                destinationEmail: $destination,
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
            Log::add(
                entry:    'OrderstatusController::sendTestEmail failed: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.orderemail',
            );
        }

        $app->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $app->sendHeaders();

        echo $this->renderSendTestResultHtml(
            statusId:    $statusId,
            destination: $destination,
            error:       $error,
        );

        $app->close();
    }

    /**
     * Build the send-test form HTML.
     *
     * Self-contained document — runs inside an iframe loaded by the
     * Bootstrap modal. Uses light Bootstrap styling so it visually
     * matches the admin chrome.
     *
     * @param int                       $statusId  Status PK being tested.
     * @param \Joomla\CMS\User\User      $user      Current admin (for default destination email).
     * @param string                    $recipient Recipient ('customer'|'admin') — from the composer button.
     * @param string                    $lang      Language tag — from the active composer tab.
     *
     * @return string Complete HTML page.
     *
     * @since   1.0.4
     */
    private function renderSendTestFormHtml(int $statusId, $user, string $recipient = 'customer', string $lang = ''): string
    {
        $orders     = $this->loadRecentOrdersForPicker(limit: 25);
        $userEmail  = (string) ($user->email ?? '');

        // Recipient + language are fixed by the composer context (button +
        // active tab), so they're hidden inputs here, not pickers.
        $recipient = in_array($recipient, ['customer', 'admin'], true) ? $recipient : 'customer';

        if ($lang === '') {
            $lang = Factory::getApplication()->getLanguage()->getTag();
        }

        $langObj   = LanguageHelper::getLanguages('lang_code')[$lang] ?? null;
        $langName  = (string) ($langObj->title_native ?? $lang);
        $rcptLabel = Text::_('COM_ALFA_FORM_FIELDSET_ORDER_STATUS_EMAILS_' . strtoupper($recipient));

        $submitUrl  = Route::_(sprintf(
            'index.php?option=com_alfa&task=orderstatus.sendTestEmail&tmpl=component&status_id=%d',
            $statusId,
        ), false);

        $orderOptions = '';
        foreach ($orders as $order) {
            $label = sprintf(
                '#%s — %s',
                $order->invoice_number > 0 ? (string) $order->invoice_number : (string) $order->id,
                (string) ($order->created ?? ''),
            );
            $orderOptions .= sprintf(
                '<option value="%d">%s</option>',
                (int) $order->id,
                htmlspecialchars($label, ENT_COMPAT, 'UTF-8'),
            );
        }

        if ($orderOptions === '') {
            $orderOptions = sprintf(
                '<option value="">%s</option>',
                htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_NO_ORDERS'), ENT_COMPAT, 'UTF-8'),
            );
        }

        $heading = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_FORM_HEADING'), ENT_COMPAT, 'UTF-8');
        $lblOrder = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_LBL_ORDER'), ENT_COMPAT, 'UTF-8');
        $lblOrderHint = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_LBL_ORDER_HINT'), ENT_COMPAT, 'UTF-8');
        $lblDestination = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_LBL_DESTINATION'), ENT_COMPAT, 'UTF-8');
        $lblSubmit = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_LBL_SUBMIT'), ENT_COMPAT, 'UTF-8');
        $userEmailEsc = htmlspecialchars($userEmail, ENT_COMPAT, 'UTF-8');
        $submitUrlEsc = htmlspecialchars($submitUrl, ENT_COMPAT, 'UTF-8');

        // Read-only context line: which email is being tested.
        $context = htmlspecialchars(
            Text::sprintf('COM_ALFA_ORDEREMAIL_TEST_CONTEXT', $rcptLabel, $langName),
            ENT_COMPAT,
            'UTF-8',
        );
        $recipientEsc = htmlspecialchars($recipient, ENT_COMPAT, 'UTF-8');
        $langEsc      = htmlspecialchars($lang, ENT_COMPAT, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Send test email</title>
    <link rel="stylesheet" href="/media/templates/administrator/atum/css/template.min.css">
</head>
<body class="bg-light">
    <div class="container py-4" style="max-width:560px;">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-2">{$heading}</h5>
                <p class="text-muted small mb-4">{$context}</p>
                <form method="post" action="{$submitUrlEsc}">
                    <input type="hidden" name="recipient" value="{$recipientEsc}">
                    <input type="hidden" name="lang" value="{$langEsc}">
                    <div class="mb-3">
                        <label for="alfa-test-order" class="form-label fw-semibold">{$lblOrder}</label>
                        <select id="alfa-test-order" name="order_id" class="form-select" required>{$orderOptions}</select>
                        <div class="form-text">{$lblOrderHint}</div>
                    </div>
                    <div class="mb-3">
                        <label for="alfa-test-destination" class="form-label fw-semibold">{$lblDestination}</label>
                        <input id="alfa-test-destination" type="email" name="destination" class="form-control" value="{$userEmailEsc}" required>
                    </div>
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary">{$lblSubmit}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build the result page (success or error) shown after submission.
     *
     * @param int         $statusId    Status PK (for "Send another" link).
     * @param string      $destination Destination email (for confirmation).
     * @param string|null $error       Error message or null on success.
     *
     * @return string Complete HTML page.
     *
     * @since   1.0.4
     */
    private function renderSendTestResultHtml(int $statusId, string $destination, ?string $error): string
    {
        $isError    = $error !== null;
        $alertClass = $isError ? 'alert-danger' : 'alert-success';

        $title = htmlspecialchars(
            Text::_($isError ? 'COM_ALFA_ORDEREMAIL_TEST_RESULT_FAILED' : 'COM_ALFA_ORDEREMAIL_TEST_RESULT_OK'),
            ENT_COMPAT,
            'UTF-8',
        );

        $body = $isError
            ? htmlspecialchars((string) $error, ENT_COMPAT, 'UTF-8')
            : htmlspecialchars(
                Text::sprintf('COM_ALFA_ORDEREMAIL_TEST_RESULT_OK_BODY', $destination),
                ENT_COMPAT,
                'UTF-8',
            );

        $backUrl = Route::_(sprintf(
            'index.php?option=com_alfa&task=orderstatus.sendTestForm&tmpl=component&id=%d',
            $statusId,
        ), false);
        $backUrlEsc = htmlspecialchars($backUrl, ENT_COMPAT, 'UTF-8');

        $backLabel = htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_TEST_RESULT_BACK'), ENT_COMPAT, 'UTF-8');

        // tmpl=component strips chrome but Joomla still emits the admin
        // template's CSS — that ships Bootstrap so .alert / .btn-link work
        // without any local styles.
        $bootstrapUrl = Uri::root(true) . '/media/templates/administrator/atum/css/template.min.css';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Send test email — result</title>
    <link rel="stylesheet" href="{$bootstrapUrl}">
</head>
<body class="p-4">
    <div class="container" style="max-width:560px;">
        <div class="alert {$alertClass}" role="alert">
            <h4 class="alert-heading mb-2">{$title}</h4>
            <p class="mb-0">{$body}</p>
        </div>
        <div class="text-center mt-3">
            <a class="btn btn-link" href="{$backUrlEsc}">{$backLabel}</a>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Load the most recent orders for the send-test form's picker.
     *
     * Plain `#__alfa_orders` rows ordered by created DESC. Limited to
     * keep the dropdown manageable; admin who needs an older order can
     * always pick the closest recent one for token resolution (the
     * tokens that vary between orders are mostly customer/items, and
     * any recent order will exercise them).
     *
     * @param int $limit Max rows.
     *
     * @return object[]
     *
     * @since   1.0.4
     */
    private function loadRecentOrdersForPicker(int $limit): array
    {
        try {
            $db    = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('invoice_number'),
                    $db->quoteName('created'),
                ])
                ->from($db->quoteName('#__alfa_orders'))
                ->order($db->quoteName('created') . ' DESC')
                ->setLimit($limit);

            $db->setQuery(query: $query);

            return $db->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Render a minimal HTML error page in place of an email preview.
     *
     * Used when previewEmail() can't build the payload (bad id, missing
     * status row, render exception). The error stays visible in the
     * modal's iframe so admin sees what went wrong without opening a
     * separate log file.
     *
     * @param string $message Plain-text error message.
     *
     * @return void
     *
     * @since   1.0.4
     */
    private function emitPreviewError(string $message): void
    {
        $msg = htmlspecialchars($message, ENT_COMPAT, 'UTF-8');

        $this->app->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $this->app->sendHeaders();

        echo '<!doctype html><html><head><meta charset="UTF-8"><title>Preview error</title>'
            . '<style>body{margin:0;padding:40px;font-family:-apple-system,Helvetica,Arial,sans-serif;background:#fef6ec;color:#a44a2b;}'
            . 'h1{font-size:18px;margin:0 0 12px 0;}p{margin:0;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#1c1814;}</style>'
            . '</head><body><h1>Email preview could not be rendered</h1><p>' . $msg . '</p></body></html>';

        $this->app->close();
    }
}
