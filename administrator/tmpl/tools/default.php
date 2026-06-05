<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.sync-tools');

// Generic completion label for actions whose server reply carries no message (backfill).
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');

// CSRF token carried in the AJAX URLs (validated server-side via Session::checkToken).
$token    = Session::getFormToken();
$ajaxBase = 'index.php?option=com_alfa&' . $token . '=1';

/**
 * Inline progress block markup, shared by every card. The JS resolves the bar
 * relative to the clicked button (closest .card-body), so the markup is identical.
 */
$progress = '<div class="alfa-progress mt-3" style="display:none;">'
    . '<div class="progress" style="height:1.5rem;">'
    . '<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"'
    . ' style="width:0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>'
    . '</div>'
    . '<p class="small text-muted mt-2 mb-0" data-status></p>'
    . '</div>';
?>
<div class="com-alfa-tools p-3">

    <?php // Outer tabset carries recall; the devguide's inner tabset must NOT, or
          // nested recall shares one sessionStorage key and can hang the page. ?>
    <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaTools', ['active' => 'media', 'recall' => true]); ?>

    <?php /* ---------- Media ---------- */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'media', Text::_('COM_ALFA_TOOLS_TAB_MEDIA')); ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_MEDIA_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_MEDIA_DESC'); ?></p>
                <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_alfa&view=toolsmedia'); ?>">
                    <span class="icon-images" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_MEDIA_BTN'); ?>
                </a>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* ---------- Database Sync (users → languages → backfill, in run order) ---------- */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'dbsync', Text::_('COM_ALFA_TOOLS_TAB_DBSYNC')); ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_USERS_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_USERS_DESC'); ?></p>
                <button type="button" class="btn btn-secondary alfa-tool-btn" data-action="single"
                        data-url="<?php echo $this->escape($ajaxBase . '&task=sync.users'); ?>">
                    <span class="icon-users" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_RESYNC_USERS'); ?>
                </button>
                <?php echo $progress; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_LANG_SCHEMA_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_LANG_SCHEMA_DESC'); ?></p>
                <button type="button" class="btn btn-primary alfa-tool-btn" data-action="single"
                        data-url="<?php echo $this->escape($ajaxBase . '&task=sync.languages'); ?>">
                    <span class="icon-refresh" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLBAR_RESYNC_LANGUAGES'); ?>
                </button>
                <?php echo $progress; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_DESC'); ?></p>
                <button type="button" class="btn btn-warning alfa-tool-btn" data-action="backfill"
                        data-plan-url="<?php echo $this->escape($ajaxBase . '&task=sync.backfillPlan'); ?>"
                        data-chunk-url="<?php echo $this->escape($ajaxBase . '&task=sync.backfillChunk'); ?>">
                    <span class="icon-language" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_BACKFILL_BTN'); ?>
                </button>
                <?php echo $progress; ?>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* ---------- GitHub — Commit & Pull Request ---------- */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'github', Text::_('COM_ALFA_TOOLS_TAB_GITHUB')); ?>
        <?php echo $this->loadTemplate('devguide'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* ---------- Security — file integrity ---------- */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'integrity', Text::_('COM_ALFA_TOOLS_INTEGRITY_TAB')); ?>
        <?php /* Official-release verification against the SIGNED CDN checksums.
                 'modified' = exact-version deviation (alarm); 'ahead' = off-catalog
                 / customised (calm); 'unreachable' = network state; 'bad_signature'
                 = do not trust the reference. */ ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_OFFICIAL_TITLE'); ?></div>
            <div class="card-body">
                <?php $ostatus = $this->official['status'] ?? 'unreachable'; ?>

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
                        <?php /* "This is NOT the official release" is the real alarm → always red.
                                 The per-type lists below are graded (injected red, modified/missing amber). */ ?>
                        <div class="alert alert-danger" role="alert">
                            <strong><?php echo Text::sprintf('COM_ALFA_TOOLS_OFFICIAL_MODIFIED', $this->escape($this->official['officialVersion'] ?? '')); ?></strong>
                        </div>
                    <?php else : ?>
                        <div class="alert alert-info" role="alert">
                            <?php echo Text::sprintf('COM_ALFA_TOOLS_OFFICIAL_AHEAD', $this->escape($this->official['officialVersion'] ?? '')); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasInjected) : ?>
                        <?php /* Injected: red on an exact-version install; calm (info) when just ahead/customised. */ ?>
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

                <?php /* Standing honesty note — an on-box check can't beat a fully
                         compromised server (it could neuter the check or block the CDN);
                         an external off-box monitor is the real backstop. */ ?>
                <p class="small text-muted mt-3 mb-0">
                    <span class="icon-info-circle" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_INTEGRITY_CAVEAT'); ?>
                </p>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

</div>
