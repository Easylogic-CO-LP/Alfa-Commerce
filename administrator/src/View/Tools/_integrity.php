<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Integrity verdict card.
 *
 * Rendered from {@see HtmlView::display()} via a fixed-path include — deliberately NOT
 * through Joomla's overridable layout/template system — so a template override cannot
 * silently fake the verification result. The Tools template only echoes the string this
 * produces; the verdict logic never lives in an override-able file. To change what this
 * shows you must edit this source file, which is itself covered by the signed checksums.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tools\HtmlView $this
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

$ostatus = $this->official['status'] ?? 'unreachable';
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_OFFICIAL_TITLE'); ?></div>
    <div class="card-body">

        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <a class="btn btn-secondary btn-sm"
               href="<?php echo Route::_('index.php?option=com_alfa&task=tools.recheckIntegrity&' . Session::getFormToken() . '=1'); ?>">
                <span class="icon-loop" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_RECHECK'); ?>
            </a>
            <?php if (!empty($this->official['checkedAt'])) : ?>
                <span class="small text-muted">
                    <?php echo Text::sprintf('COM_ALFA_TOOLS_INTEGRITY_LAST_CHECKED', HTMLHelper::_('date', '@' . (int) $this->official['checkedAt'], Text::_('DATE_FORMAT_LC2'))); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($ostatus === 'official') : ?>
            <div class="alert alert-success mb-0" role="alert">
                <span class="icon-checkmark-circle" aria-hidden="true"></span>
                <?php echo Text::sprintf('COM_ALFA_TOOLS_OFFICIAL_OK', $this->escape($this->official['officialVersion'] ?? '')); ?>
            </div>

        <?php elseif ($ostatus === 'unreachable') : ?>
            <p class="small text-muted mb-0">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_OFFICIAL_UNREACHABLE'); ?>
            </p>

        <?php elseif ($ostatus === 'bad_signature') : ?>
            <div class="alert alert-danger mb-0" role="alert">
                <span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_('COM_ALFA_TOOLS_OFFICIAL_BAD_SIG'); ?>
            </div>

        <?php elseif ($ostatus === 'ahead' || $ostatus === 'modified') : ?>
            <?php
            // Graded severity (no blanket red): injected = the one true red
            // (web-shell shape); modified/missing = amber (review); ahead = calm.
            $alarm       = ($ostatus === 'modified');
            $hasInjected = !empty($this->official['injected']);
            ?>
            <?php if ($alarm) : ?>
                <div class="alert alert-danger" role="alert">
                    <strong><?php echo Text::sprintf('COM_ALFA_TOOLS_OFFICIAL_MODIFIED', $this->escape($this->official['officialVersion'] ?? '')); ?></strong>
                </div>
            <?php else : ?>
                <div class="alert alert-info" role="alert">
                    <?php echo Text::sprintf('COM_ALFA_TOOLS_OFFICIAL_AHEAD', $this->escape($this->official['officialVersion'] ?? '')); ?>
                </div>
            <?php endif; ?>

            <?php if ($hasInjected) : ?>
                <div class="alert <?php echo $alarm ? 'alert-danger' : 'alert-info'; ?>" role="alert">
                    <strong><?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_ADDED'); ?></strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($this->official['injected'] as $path) : ?>
                            <li><code><?php echo $this->escape($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($this->official['modified'])) : ?>
                <div class="alert <?php echo $alarm ? 'alert-warning' : 'alert-secondary'; ?>" role="alert">
                    <strong><?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_MODIFIED'); ?></strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($this->official['modified'] as $path) : ?>
                            <li><code><?php echo $this->escape($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($this->official['missing'])) : ?>
                <div class="alert <?php echo $alarm ? 'alert-warning' : 'alert-secondary'; ?>" role="alert">
                    <strong><?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_MISSING'); ?></strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($this->official['missing'] as $path) : ?>
                            <li><code><?php echo $this->escape($path); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p class="small text-muted mt-3 mb-0">
            <span class="icon-info-circle" aria-hidden="true"></span>
            <?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_CAVEAT'); ?>
        </p>
    </div>
</div>
