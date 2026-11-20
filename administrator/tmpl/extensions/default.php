<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Tools > Extensions: the Alfa extensions installed apart from Alfa Commerce, one row each (a package with its
 * extensions inside it, each with its own verdict), and the dialogs of the three toolbar actions.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Extensions\HtmlView $this
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Setup\ExtensionCompatibility;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

// The Alfa administrator stylesheet: raw stored values wrap in their cells (raw-value).
$this->getDocument()->getWebAssetManager()->useStyle('com_alfa.admin');

$type = static fn (array $entry): string => match ($entry['type']) {
    'package' => Text::_('COM_ALFA_EXTENSIONS_TYPE_PACKAGE'),
    'plugin' => Text::sprintf('COM_ALFA_EXTENSIONS_TYPE_PLUGIN', $entry['folder']),
    'module' => Text::_((int) $entry['client_id'] === 1 ? 'COM_ALFA_EXTENSIONS_TYPE_MODULE_ADMIN' : 'COM_ALFA_EXTENSIONS_TYPE_MODULE_SITE'),
    default => ucfirst((string) $entry['type']),
};
// Whether a plugin or module is built for this version of Alfa Commerce, as its manifest states it; a package is judged
// by its extensions, and a plugin of another vendor's group that is not Alfa's has no verdict.
$verdict = function (array $entry): string {
    $verdict = $this->compatibility[(string) $entry['key']] ?? null;

    if ($verdict === null) {
        return '';
    }

    // The range a build that needs replacing declares: the line it is built for.
    $range = $verdict['range'];
    $builtFor = match (true) {
        $range === null => '',
        $range->max() === null => Text::sprintf('COM_ALFA_EXTENSIONS_BUILT_FOR_FROM', $range->min()),
        $range->max() === $range->min() => Text::sprintf('COM_ALFA_EXTENSIONS_BUILT_FOR', $range->min()),
        default => Text::sprintf('COM_ALFA_EXTENSIONS_BUILT_FOR_RANGE', $range->min(), $range->max()),
    };

    return match ($verdict['verdict']) {
        ExtensionCompatibility::COMPATIBLE => '<span class="badge bg-success">' . Text::_('COM_ALFA_EXTENSIONS_VERDICT_COMPATIBLE') . '</span>',
        ExtensionCompatibility::NEEDS_BUILD => '<span class="badge bg-warning text-dark">' . Text::_('COM_ALFA_EXTENSIONS_VERDICT_NEEDS_BUILD') . '</span>'
            . '<div class="small">' . $this->escape($builtFor) . '</div>',
        default => '<span class="badge bg-secondary">' . Text::_('COM_ALFA_EXTENSIONS_VERDICT_NOT_STATED') . '</span>'
            . '<div class="small">' . Text::_('COM_ALFA_EXTENSIONS_NOT_STATED_WHY') . '</div>',
    };
};
$clearData = function (array $entry): string {
    $support = (array) $entry['support'];
    $html = match ($support['status']) {
        'supported' => '<span class="badge bg-success">' . Text::_('COM_ALFA_EXTENSIONS_SUPPORTED') . '</span>',
        'unsupported' => '<span class="badge bg-warning text-dark">' . Text::_('COM_ALFA_EXTENSIONS_UNSUPPORTED') . '</span>',
        // An Alfa plugin that could not be loaded, or a plugin of another group that could not be read.
        'failed', 'unread' => '<span class="badge ' . ($support['status'] === 'failed' ? 'bg-danger' : 'bg-secondary') . '">'
            . Text::_($support['status'] === 'failed' ? 'COM_ALFA_EXTENSIONS_NOT_LOADED' : 'COM_ALFA_EXTENSIONS_NOT_READ') . '</span>'
            . '<div class="small">' . $this->escape((string) $support['message']) . '</div>'
            . (!empty($support['stale'])
                ? '<div class="small"><a href="' . Route::_('index.php?option=com_alfa&task=extensions.retryLoad&id=' . (int) $entry['extension_id'] . '&' . Session::getFormToken() . '=1') . '">'
                    . Text::_('COM_ALFA_EXTENSIONS_RETRY_LOAD') . '</a></div>'
                : ''),
        'busy' => '<span class="badge bg-secondary">' . $this->escape((string) $support['message']) . '</span>',
        default => '',
    };

    // What a plugin says it clears and keeps, only when it has the hook that does it.
    if ($support['status'] === 'supported' && (string) $entry['description'] !== '') {
        $html .= '<div class="small text-muted">' . $this->escape((string) $entry['description']) . '</div>';
    }

    $last = $entry['last'];

    if (\is_array($last) && $support['status'] !== 'remove_only') {
        $html .= '<div class="small">' . ($last['status'] === 'clearing'
            ? Text::_('COM_ALFA_EXTENSIONS_CLEARING')
            : Text::sprintf('COM_ALFA_EXTENSIONS_LAST', Text::_('COM_ALFA_CLEAR_STATUS_' . strtoupper((string) $last['status'])), $this->escape((string) $last['at']))
                . ((string) $last['message'] !== '' ? ': ' . $this->escape((string) $last['message']) : '')) . '</div>';
    }

    return $html;
};
?>
<form action="<?php echo Route::_('index.php?option=com_alfa&view=extensions'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <div class="alert alert-info" role="alert">
                    <span class="icon-info-circle" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_EXTENSIONS_NOTE'); ?>
                </div>

                <?php if ($this->busy) : ?>
                    <div class="alert alert-warning" role="status">
                        <span class="icon-clock" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_EXTENSIONS_BUSY_NOTE'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($this->rows === []) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_ALFA_EXTENSIONS_NONE'); ?>
                    </div>
                <?php else : ?>
                <table class="table table-striped" id="extensionsList">
                    <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_EXTENSIONS_TITLE'); ?></caption>
                    <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                        <th scope="col"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_EXTENSION'); ?></th>
                        <th scope="col" class="w-15"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_TYPE'); ?></th>
                        <th scope="col" class="w-10"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_VERSION'); ?></th>
                        <th scope="col" class="w-10"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_STATE'); ?></th>
                        <th scope="col" class="w-15"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_COMPATIBILITY'); ?></th>
                        <th scope="col" class="w-25"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_CLEAR_DATA'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->rows as $i => $row) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $row['extension_id']); ?></td>
                            <th scope="row">
                                <?php echo $this->escape((string) $row['label']); ?>
                                <div class="small text-muted"><code class="raw-value"><?php echo $this->escape((string) $row['key']); ?></code></div>
                                <?php if ($row['children'] !== []) : ?>
                                    <ul class="small fw-normal mb-0 mt-1">
                                        <?php foreach ($row['children'] as $child) : ?>
                                            <li>
                                                <?php echo $this->escape((string) $child['label']); ?>
                                                <span class="text-muted">(<?php echo $this->escape($type($child)); ?>)</span>
                                                <?php echo $verdict($child); ?>
                                                <?php echo $child['type'] === 'plugin' ? $clearData($child) : ''; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </th>
                            <td><?php echo $this->escape($type($row)); ?></td>
                            <td><?php echo $row['version'] !== '' ? $this->escape((string) $row['version']) : '-'; ?></td>
                            <td><?php echo $row['enabled']
                                ? '<span class="badge bg-success">' . Text::_('COM_ALFA_EXTENSIONS_STATE_ENABLED') . '</span>'
                                : '<span class="badge bg-secondary">' . Text::_('COM_ALFA_EXTENSIONS_STATE_DISABLED') . '</span>'; ?></td>
                            <td><?php echo $verdict($row); ?></td>
                            <td><?php if ($row['type'] === 'package' && $row['support']['status'] === 'remove_only') : ?>
                                <?php // A language pack holds no data; it goes when Alfa Commerce is uninstalled. ?>
                                <?php echo $row['language_pack'] ? '<span class="small text-muted">' . Text::_('COM_ALFA_EXTENSIONS_LANGUAGE_PACK') . '</span>' : ''; ?>
                            <?php else : ?>
                                <?php echo $clearData($row); ?>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <template id="joomla-dialog-extensions-clear"><?php echo $this->loadTemplate('clear_body'); ?></template>
                <template id="joomla-dialog-extensions-remove"><?php echo $this->loadTemplate('remove_body'); ?></template>
                <template id="joomla-dialog-extensions-removedata"><?php echo $this->loadTemplate('removedata_body'); ?></template>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
