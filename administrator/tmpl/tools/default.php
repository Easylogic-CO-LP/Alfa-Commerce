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

/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('com_alfa.sync-tools');
// The component's administrator stylesheet: the cards' lists folded on a phone.
$wa->useStyle('com_alfa.admin');

// Generic completion label for actions whose server reply carries no message.
Text::script('COM_ALFA_TOOLS_BACKFILL_DONE');
Text::script('COM_ALFA_SYNC_ERROR_PREFIX');
Text::script('COM_ALFA_TOOLS_JOB_OPENING');
Text::script('COM_ALFA_TOOLS_REQUEST_FAILED');

/**
 * EVERY action card renders through one registry loop and one layout. There is no
 * second renderer and no hard-coded card: core's own tools are collected through the
 * same event as a plugin's ({@see \Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider}),
 * so the path an engine plugin's card takes is exercised on every Tools page load.
 * Order within a tab is each action's declared priority; for core's Maintenance tools
 * that priority encodes RUN order (the media tools, then the database sync tools, the
 * guided tours last), so the tab reads top-to-bottom as "do them in this order".
 */
$renderActions = static function (array $actions): string {
    $html = '';

    foreach ($actions as $action) {
        $html .= LayoutHelper::render('actions.toolcard', ['action' => $action], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
    }

    return $html;
};
$renderSurface = static fn (string $surface): string => $renderActions(ToolRegistry::forSurface($surface));

$toolsUser = Factory::getApplication()->getIdentity();

// The Development tab is for whoever changes the code (the contribution guide with the package export, the tours'
// export): Super Users see it, nobody else.
$showDevelopment = $toolsUser->authorise('core.admin');

/** Tab ids this template renders itself (the Background tab is `tasks`: the Background screen is view=tasks, and
 *  the web-cron key returns to it by that id), the surface whose actions are the Background screen's toolbar, and
 *  the development surface, rendered only for a Super User; anything else a plugin names opens its own tab. */
$coreSurfaces = ['maintenance', 'tasks', 'security', 'extensions', 'development', 'background'];
?>
<div class="com-alfa-tools p-3">

    <?php // Outer tabset carries recall; the devguide's inner tabset must NOT, or
          // nested recall shares one sessionStorage key and can hang the page. ?>
    <?php // A new web-cron address opens on the Background tab, whatever tab was last open.
    $onTasks = $this->newCronKey !== ''; ?>
    <?php echo HTMLHelper::_('uitab.startTabSet', 'alfaTools', ['active' => $onTasks ? 'tasks' : 'maintenance', 'recall' => !$onTasks]); ?>

    <?php /* Maintenance: the media tools, the database sync tools in run order and the guided tours (the registry
             loop, in declared priority), then reset and clear, each of which opens its own page that shows what it
             would change before anything is changed. */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'maintenance', Text::_('COM_ALFA_TOOLS_TAB_MAINTENANCE')); ?>
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
        <?php echo $renderSurface('maintenance'); ?>
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

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Background: how much background work there is, in the words of the Background screen, which lists it and
             runs its tools; then any card a plugin adds to the tab, and how the background work runs (set once, read
             rarely) */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'tasks', Text::_('COM_ALFA_TOOLS_TAB_BACKGROUND')); ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TITLE_TASKS'); ?></div>
            <div class="card-body">
                <?php if ($this->background['unreadable']) : ?>
                    <div class="alert alert-warning"><span class="icon-warning" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ALFA_TASKS_UNREADABLE'); ?></div>
                <?php else : ?>
                    <?php $work = $this->background['work']; $deliveries = $this->background['deliveries']; ?>
                    <p class="mb-1"><?php echo Text::sprintf('COM_ALFA_TOOLS_BACKGROUND_WORK', (int) ($work['running'] ?? 0), (int) ($work['waiting'] ?? 0), (int) ($work['attention'] ?? 0), (int) ($work['finished'] ?? 0)); ?></p>
                    <p class="text-muted mb-3"><?php echo Text::sprintf('COM_ALFA_TOOLS_BACKGROUND_DELIVERIES', (int) ($deliveries['waiting'] ?? 0) + (int) ($deliveries['running'] ?? 0), (int) ($deliveries['attention'] ?? 0)); ?></p>
                <?php endif; ?>
                <a class="btn <?php echo (int) ($this->background['work']['attention'] ?? 0) > 0 ? 'btn-warning' : 'btn-secondary'; ?>" href="<?php echo Route::_('index.php?option=com_alfa&view=tasks'); ?>">
                    <span class="icon-loop" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_BACKGROUND_BTN'); ?>
                </a>
            </div>
        </div>
        <?php echo $renderSurface('tasks'); ?>
        <?php echo LayoutHelper::render('tasks.runner', $this->runner + ['newKey' => $this->newCronKey], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>

    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php /* Security: file integrity and the REST API's addresses */ ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'security', Text::_('COM_ALFA_TOOLS_TAB_SECURITY')); ?>
        <?php /* Official-release verification against the SIGNED CDN checksums.
                 'modified' = exact-version deviation (alarm); 'ahead' = off-catalog
                 / customised (calm); 'unreachable' = network state; 'bad_signature'
                 = do not trust the reference. */ ?>
        <?php /* Verdict card is built in the View from a fixed-path partial (NOT the
                 overridable layout system), so a template override cannot fake it. */ ?>
        <?php echo $this->integrityHtml; ?>

        <?php /* The REST API's endpoints and who may call them: the counts here, the report with its filters on its own page. */ ?>
        <div class="card mb-3">
            <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TITLE'); ?></div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    <?php if ($this->apiSummary['unreadable']) : ?>
                        <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_UNREADABLE'); ?>
                    <?php else : ?>
                        <?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_SUMMARY', $this->apiSummary['addresses'], $this->apiSummary['plugins'], $this->apiSummary['accounts'], $this->apiSummary['superUsers']); ?>
                    <?php endif; ?>
                </p>
                <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_alfa&view=apiroutes'); ?>">
                    <span class="icon-code" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_TITLE_APIROUTES'); ?>
                </a>
            </div>
        </div>

        <?php echo $renderSurface('security'); ?>

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

    <?php /* Development: the contribution guide with the package export, and the tools for whoever changes the code */ ?>
    <?php if ($showDevelopment) : ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'alfaTools', 'development', Text::_('COM_ALFA_TOOLS_TAB_DEVELOPMENT')); ?>
            <?php echo $this->loadTemplate('devguide'); ?>
            <?php echo $renderSurface('development'); ?>

        <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endif; ?>

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
            <?php echo $renderActions($surfaceActions); ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endforeach; ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

</div>
