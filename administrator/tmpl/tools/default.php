<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ToolRegistry;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\ResetToDefault;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;

/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.sync-tools');
// The component's administrator stylesheet: the jobs list's dates on one line, its rows folded on a phone.
$wa->useStyle('com_alfa.admin');

// Generic completion label for actions whose server reply carries no message.
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');
Text::script('COM_ALFA_SYNC_ERROR_PREFIX');
Text::script('COM_ALFA_TOOLS_JOB_OPENING');
Text::script('COM_ALFA_TOOLS_REQUEST_FAILED');

// CSRF token carried in the AJAX URLs (validated server-side via Session::checkToken).
$token    = Session::getFormToken();
$ajaxBase = 'index.php?option=com_alfa&' . $token . '=1';

/**
 * EVERY action card renders through one registry loop and one layout. There is no
 * second renderer and no hard-coded card: core's own tools are collected through the
 * same event as a plugin's ({@see \Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider}),
 * so the path an engine plugin's card takes is exercised on every Tools page load.
 * Order within a tab is each action's declared priority; for core's Database Sync
 * tools that priority encodes RUN order, so the tab reads top-to-bottom as
 * "do them in this order".
 */
$renderActions = static function (array $actions) use ($ajaxBase): string {
    $html = '';

    foreach ($actions as $action) {
        $html .= LayoutHelper::render(
            'actions.toolcard',
            ['action' => $action, 'ajaxBase' => $ajaxBase],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    return $html;
};
$renderSurface = static fn (string $surface): string => $renderActions(ToolRegistry::forSurface($surface));

// Tools > Tasks reads in the order staff act (UX-10): what needs attention and the run that retries it, the jobs and the
// deletion of finished ones, any other card of the tab, and last how the background work runs (set once, read rarely).
$tasksActions = ToolRegistry::forSurface('tasks');
$tasksAction = static fn (string $id): array => array_values(array_filter($tasksActions, static fn ($action): bool => $action->id === $id));
$tasksOthers = array_values(array_filter($tasksActions, static fn ($action): bool => !\in_array($action->id, ['tasks.run', 'tasks.prune'], true)));

/** Tab ids this template renders itself; anything else a plugin names opens its own tab. */
$coreSurfaces = ['media', 'dbsync', 'tasks', 'github', 'integrity', 'data', 'extensions'];
$toolsUser = Factory::getApplication()->getIdentity();
?>
<div class="com-alfa-tools p-3">

    <?php // Outer tabset carries recall; the devguide's inner tabset must NOT, or
          // nested recall shares one sessionStorage key and can hang the page. ?>
    <?php // The progress page (&job=N) and a new web-cron address open on the Tasks tab, whatever tab was last open.
    $onTasks = $this->job !== null || $this->jobMissing || $this->newCronKey !== ''; ?>
    <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaTools', ['active' => $onTasks ? 'tasks' : 'media', 'recall' => !$onTasks]); ?>

    <?php /* Media */ ?>
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
        <?php echo $renderSurface('media'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Database Sync: registry-only, in declared run order */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'dbsync', Text::_('COM_ALFA_TOOLS_TAB_DBSYNC')); ?>
        <?php echo $renderSurface('dbsync'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Tasks: the job the progress page follows; what a save left failed or with an unknown outcome, for staff to act on,
             and the run that retries it; the jobs and the prune; how the background work runs (UX-10) */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'tasks', Text::_('COM_ALFA_TOOLS_TAB_TASKS')); ?>
        <?php echo LayoutHelper::render('tasks.progress', ['job' => $this->job, 'missing' => $this->jobMissing, 'id' => RequestScalar::int(Factory::getApplication()->getInput(), 'job')], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
        <?php echo LayoutHelper::render('tasks.attention', $this->tasks + ['failedJobs' => $this->jobs['failedJobs']], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
        <?php echo $renderActions($tasksAction('tasks.run')); ?>
        <?php echo LayoutHelper::render('tasks.jobs', $this->jobs, JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
        <?php echo $renderActions($tasksAction('tasks.prune')); ?>
        <?php echo $renderActions($tasksOthers); ?>
        <?php echo LayoutHelper::render('tasks.runner', $this->runner + ['newKey' => $this->newCronKey], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* GitHub: Commit & Pull Request */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'github', Text::_('COM_ALFA_TOOLS_TAB_GITHUB')); ?>
        <?php echo $this->loadTemplate('devguide'); ?>
        <?php echo $renderSurface('github'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Security: file integrity */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'integrity', Text::_('COM_ALFA_TOOLS_INTEGRITY_TAB')); ?>
        <?php /* Official-release verification against the SIGNED CDN checksums.
                 'modified' = exact-version deviation (alarm); 'ahead' = off-catalog
                 / customised (calm); 'unreachable' = network state; 'bad_signature'
                 = do not trust the reference. */ ?>
        <?php /* Verdict card is built in the View from a fixed-path partial (NOT the
                 overridable layout system), so a template override cannot fake it. */ ?>
        <?php echo $this->integrityHtml; ?>

        <?php echo $renderSurface('integrity'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Reset and clear: each action opens its own page, which shows what it would change
             before anything is changed. */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'data', Text::_('COM_ALFA_TOOLS_TAB_DATA')); ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_RESET_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_RESET_DESC'); ?></p>
                <?php if (ResetToDefault::permitted($toolsUser)) : ?>
                    <a class="btn btn-warning" href="<?php echo Route::_('index.php?option=com_alfa&view=resetdefaults'); ?>">
                        <span class="icon-refresh" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_TOOLS_RESET_BTN'); ?>
                    </a>
                <?php else : ?>
                    <p class="small mb-0"><?php echo Text::_('COM_ALFA_TOOLS_RESET_NOT_PERMITTED'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mb-3 border-danger">
            <div class="card-header"><?php echo Text::_('COM_ALFA_CLEAR_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_CLEAR_DESC'); ?></p>
                <?php if (FactoryReset::permitted($toolsUser)) : ?>
                    <a class="btn btn-danger" href="<?php echo Route::_('index.php?option=com_alfa&view=cleardata'); ?>">
                        <span class="icon-trash" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_TOOLS_CLEAR_BTN'); ?>
                    </a>
                <?php else : ?>
                    <p class="small mb-0"><?php echo Text::_('COM_ALFA_TOOLS_CLEAR_NOT_PERMITTED'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php echo $renderSurface('data'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Extensions: the separately installed Alfa extensions, their Clear Data and their removal. */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'extensions', Text::_('COM_ALFA_TOOLS_TAB_EXTENSIONS')); ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_EXTENSIONS_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo Text::_('COM_ALFA_TOOLS_EXTENSIONS_DESC'); ?></p>
                <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_alfa&view=extensions'); ?>">
                    <span class="icon-puzzle-piece" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_EXTENSIONS_BTN'); ?>
                </a>
            </div>
        </div>
        <?php echo $renderSurface('extensions'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Tabs opened by plugins, for surfaces no core tab renders */ ?>
    <?php foreach (ToolRegistry::extraSurfaces($coreSurfaces) as $surface => $surfaceActions) : ?>
        <?php // The tab label is the first action's own, so a plugin owning a whole
              // tab names it without core needing a string for something it cannot know. ?>
        <?php echo HTMLHelper::_(
            'uitab.addTab',
            'alfaTools',
            $surface,
            Text::_((string) ($surfaceActions[0]->metadata['surface_label'] ?? $surfaceActions[0]->label)),
        ); ?>
            <?php foreach ($surfaceActions as $surfaceAction) : ?>
                <?php echo LayoutHelper::render(
                    'actions.toolcard',
                    ['action' => $surfaceAction, 'ajaxBase' => $ajaxBase],
                    JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
                ); ?>
            <?php endforeach; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endforeach; ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

</div>
