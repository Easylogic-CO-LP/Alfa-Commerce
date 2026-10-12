<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The download store's direct-download protection, as the administrator sees it: the security
 * warning while the store is not verified, and with `always` the verified (or outside the web
 * root) state too, each with "Check again". Rendered by the dashboard, the product's Downloads
 * panel and the Options field, administrator pages only; reading the state probes only after the
 * store's path changed ({@see \Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection::current()}).
 *
 * @var array $displayData  ['always' => bool: show the states that need no warning as well]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Download\StoreProtection;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

$always = !empty($displayData['always']);
$protection = new StoreProtection();

try {
    $result = $protection->current();
    $warning = $protection->warning($result);
} catch (Throwable $e) {
    // The reason's own words go to the log, never to the page.
    Log::add('Download store protection: the status could not be read: ' . $e->getMessage(), Log::ERROR, 'com_alfa.downloads');
    $result = null;
    $warning = Text::_('COM_ALFA_DOWNLOADS_PROTECTION_FAILED');
}

if ($warning === null && !$always) {
    return;
}

$checkAgain = Route::_(
    'index.php?option=com_alfa&task=tools.checkDownloadStore&' . Session::getFormToken() . '=1&return='
    . base64_encode(Uri::getInstance()->toString()),
    false,
);

if ($warning !== null) {
    $class = $result !== null && $result->state === StoreProtection::STATE_EXPOSED ? 'alert-danger' : 'alert-warning';
    $text = $warning;
} elseif ($result->state === StoreProtection::STATE_OUTSIDE) {
    $class = 'alert-info';
    $text = Text::sprintf('COM_ALFA_DOWNLOADS_PROTECTION_OUTSIDE', htmlspecialchars((string) $result->root, ENT_QUOTES, 'UTF-8'));
} else {
    $class = 'alert-success';
    $text = Text::sprintf('COM_ALFA_DOWNLOADS_PROTECTION_VERIFIED', htmlspecialchars((string) $result->root, ENT_QUOTES, 'UTF-8'), htmlspecialchars($result->checked_at . ' UTC', ENT_QUOTES, 'UTF-8'));
}
?>
<div class="alert <?php echo $class; ?> alfa-download-protection" data-state="<?php echo htmlspecialchars((string) ($result->state ?? 'failed'), ENT_QUOTES, 'UTF-8'); ?>">
    <p class="mb-2"><?php echo $text; ?></p>
    <a class="btn btn-sm btn-secondary" href="<?php echo htmlspecialchars($checkAgain, ENT_QUOTES, 'UTF-8'); ?>"><?php echo Text::_('COM_ALFA_DOWNLOADS_PROTECTION_CHECK_AGAIN'); ?></a>
</div>
