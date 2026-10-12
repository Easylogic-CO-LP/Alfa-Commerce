<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 *  Order-email downloads table: overridable partial (customer-only)
 *
 *  Rendered by the layout via:
 *    $render('emails.partials.order_downloads', ['downloads' => $downloads])
 *  Override at:
 *    templates/<template>/html/layouts/com_alfa/emails/partials/order_downloads.php
 *
 *  $displayData:
 *    object[]  $downloads  Each: {title, url, downloads_left, expires_at},
 *                          built by Entitlements::getLinksForOrder().
 *                          url carries the raw token; downloads_left null =
 *                          unlimited; expires_at null = no time limit.
 *
 *  Renders nothing without links (the layout also guards with !empty).
 *  The email helper only populates $downloads on the genuine customer
 *  send; admin/preview/test renders receive an empty array.
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

extract($displayData);

/** @var object[] $downloads */
$downloads = isset($downloads) && is_array($downloads) ? $downloads : [];

// Drop discovery stubs (objects without a url).
$downloads = array_values(array_filter($downloads, static fn ($d) => !empty($d->url)));

if (empty($downloads)) {
    return;
}

$thStyle = 'padding:8px 10px;text-align:left;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #111827;';
$tdStyle = 'padding:9px 10px;text-align:left;border-bottom:1px solid #e5e7eb;color:#111827;font-size:14px;';
$btnStyle = 'display:inline-block;padding:7px 16px;background-color:#111827;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;border-radius:4px;';

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="width:100%;border-collapse:collapse;margin:1em 0;">
    <thead>
        <tr>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_DOWNLOAD_FILE'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_DOWNLOADS_LEFT'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">
                <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_COL_DOWNLOAD_EXPIRES'), ENT_COMPAT, 'UTF-8'); ?>
            </th>
            <th style="<?php echo $thStyle; ?>">&nbsp;</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($downloads as $download) : ?>
            <tr>
                <td style="<?php echo $tdStyle; ?>">
                    <?php echo htmlspecialchars((string) ($download->title ?? ''), ENT_COMPAT, 'UTF-8'); ?>
                </td>
                <td style="<?php echo $tdStyle; ?>">
                    <?php echo $download->downloads_left !== null
                        ? (int) $download->downloads_left
                        : htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_DOWNLOADS_UNLIMITED'), ENT_COMPAT, 'UTF-8'); ?>
                </td>
                <td style="<?php echo $tdStyle; ?>">
                    <?php echo !empty($download->expires_at)
                        ? HTMLHelper::_('date', $download->expires_at, Text::_('DATE_FORMAT_LC4'))
                        : htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_DOWNLOADS_NO_EXPIRY'), ENT_COMPAT, 'UTF-8'); ?>
                </td>
                <td style="<?php echo $tdStyle; ?>">
                    <a href="<?php echo htmlspecialchars((string) $download->url, ENT_COMPAT, 'UTF-8'); ?>"
                       style="<?php echo $btnStyle; ?>">
                        <?php echo htmlspecialchars(Text::_('COM_ALFA_ORDEREMAIL_DOWNLOAD_BUTTON'), ENT_COMPAT, 'UTF-8'); ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
