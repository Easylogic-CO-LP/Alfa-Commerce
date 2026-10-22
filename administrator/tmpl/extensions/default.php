<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Tools > Extensions: the Alfa extensions installed apart from Alfa Commerce, one row each (a package with its
 * extensions inside it), and the dialogs of the three toolbar actions.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Extensions\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$type = static fn (array $entry): string => match ($entry['type']) {
    'package' => Text::_('COM_ALFA_EXTENSIONS_TYPE_PACKAGE'),
    'plugin' => Text::sprintf('COM_ALFA_EXTENSIONS_TYPE_PLUGIN', $entry['folder']),
    'module' => Text::_((int) $entry['client_id'] === 1 ? 'COM_ALFA_EXTENSIONS_TYPE_MODULE_ADMIN' : 'COM_ALFA_EXTENSIONS_TYPE_MODULE_SITE'),
    default => ucfirst((string) $entry['type']),
};
$clearData = function (array $entry): string {
    $support = (array) $entry['support'];
    $html = match ($support['status']) {
        'supported' => '<span class="badge bg-success">' . Text::_('COM_ALFA_EXTENSIONS_SUPPORTED') . '</span>',
        'unsupported' => '<span class="badge bg-warning text-dark">' . Text::_('COM_ALFA_EXTENSIONS_UNSUPPORTED') . '</span>',
        'failed' => '<span class="badge bg-danger">' . Text::_('COM_ALFA_EXTENSIONS_NOT_LOADED') . '</span>'
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
                        <th scope="col" class="w-25"><?php echo Text::_('COM_ALFA_EXTENSIONS_COL_CLEAR_DATA'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->rows as $i => $row) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $row['extension_id']); ?></td>
                            <th scope="row">
                                <?php echo $this->escape((string) $row['label']); ?>
                                <div class="small text-muted"><code><?php echo $this->escape((string) $row['key']); ?></code></div>
                                <?php if ($row['children'] !== []) : ?>
                                    <ul class="small fw-normal mb-0 mt-1">
                                        <?php foreach ($row['children'] as $child) : ?>
                                            <li>
                                                <?php echo $this->escape((string) $child['label']); ?>
                                                <span class="text-muted">(<?php echo $this->escape($type($child)); ?>)</span>
                                                <?php echo $child['type'] === 'plugin' ? $clearData($child) : ''; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </th>
                            <td><?php echo $this->escape($type($row)); ?></td>
                            <td><?php echo $row['version'] !== '' ? $this->escape((string) $row['version']) : '-'; ?></td>
                            <td><?php echo $row['enabled']
                                ? '<span class="badge bg-success">' . Text::_('JENABLED') . '</span>'
                                : '<span class="badge bg-secondary">' . Text::_('JDISABLED') . '</span>'; ?></td>
                            <td><?php echo $row['type'] === 'package' && $row['support']['status'] === 'remove_only' ? '' : $clearData($row); ?></td>
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
