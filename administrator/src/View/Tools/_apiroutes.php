<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The card of every Alfa Commerce endpoint and who may call it, on Tools, Security: Joomla's search tools, then three
 * sections that open on demand: Alfa Commerce's own endpoints, the extensions' endpoints (one section for each
 * extension, with the declarations core refused and whether it can answer now), and the API internals (the token's
 * sign-in groups, who holds each permission, the formats and the body limits). While a filter is set, the sections
 * that hold what it keeps are open.
 *
 * Rendered from {@see HtmlView::display()} through a fixed-path include, as the integrity verdict is, so a template
 * override cannot hide an endpoint, a permission or an account that holds a token. Read only.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tools\HtmlView $this
 */

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddress;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddressListing;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$routes = $this->apiRoutes;
$summary = $routes['summary'];
$sections = $routes['sections'];
$filtered = $routes['filtered'];
$sourceFilter = (string) ($this->activeFilters['source'] ?? '');

/** The count a section's title carries: its endpoints, or the shown ones of them while a filter is set. */
$count = static fn (int $shown, int $all): string => '<span class="badge bg-secondary ms-2">'
    . ($filtered ? Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_COUNT_SHOWN', $shown, $all) : (string) $all)
    . '</span>';

/** One section that opens on demand: Joomla's accordion with one slide, open when asked. */
$open = static function (string $id, string $title, bool $active): void {
    echo HTMLHelper::_('bootstrap.startAccordion', $id, $active ? ['active' => $id . '-slide'] : []);
    echo HTMLHelper::_('bootstrap.addSlide', $id, $title, $id . '-slide');
};
$close = static function (): void {
    echo HTMLHelper::_('bootstrap.endSlide');
    echo HTMLHelper::_('bootstrap.endAccordion');
};

/** The permission an endpoint asks, as the table names it: its title and key, per record, or none. */
$permission = function (ApiAddress $address) use ($routes): void {
    if (!$address->declared) {
        echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_PERMISSION');
    }

    if ($address->perRecord) {
        echo '<div>' . Text::_('COM_ALFA_TOOLS_API_ROUTES_PER_RECORD') . '</div>';
    }

    foreach ($address->permissions as $name) {
        $title = (string) ($routes['titles'][$name] ?? '');
        echo '<div>' . ($title !== '' ? $this->escape($title) . '<br>' : '') . '<code>' . $this->escape($name) . '</code></div>';
    }
};

/** The endpoints of one section: method, endpoint and the permission each asks. */
$endpoints = function (array $addresses, string $id, string $source) use ($permission): void {
    ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0" id="<?php echo $this->escape($id); ?>" data-source="<?php echo $this->escape($source); ?>">
            <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TITLE'); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_METHOD'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ADDRESS'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PERMISSION'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($addresses as $address) : ?>
                    <tr data-source="<?php echo $this->escape($address->source); ?>">
                        <td><?php echo $this->escape(implode(', ', $address->methods)); ?></td>
                        <td><code><?php echo $this->escape($address->address); ?></code></td>
                        <td><?php $permission($address); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TITLE'); ?></div>
    <div class="card-body">
        <p class="small text-muted"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_DESC'); ?></p>

        <?php if ($routes['unreadable']) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_UNREADABLE'); ?></p>
        <?php else : ?>
            <?php if (!$routes['webservices']) : ?>
                <p class="alert alert-warning"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_WEBSERVICES_OFF'); ?></p>
            <?php endif; ?>
            <?php if (!$routes['tokenPlugin']) : ?>
                <p class="alert alert-warning"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_PLUGIN_OFF'); ?></p>
            <?php endif; ?>

            <p><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_SUMMARY', $summary['addresses'], $summary['plugins'], $summary['accounts'], $summary['superUsers']); ?></p>

            <?php if ($this->filterForm !== null) : ?>
                <?php /* The filters are kept for the user; #integrity opens this tab after the form is sent. */ ?>
                <form action="<?php echo Route::_('index.php?option=com_alfa&view=tools'); ?>#integrity" method="get" id="alfaApiRoutesForm">
                    <input type="hidden" name="option" value="com_alfa">
                    <input type="hidden" name="view" value="tools">
                    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['formSelector' => '#alfaApiRoutesForm']]); ?>
                </form>
            <?php endif; ?>

            <?php if ($filtered) : ?>
                <p class="small"><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_SHOWN', \count($routes['shown']), $summary['addresses']); ?></p>
            <?php endif; ?>

            <?php if ($routes['shown'] === []) : ?>
                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_MATCH'); ?>
                </div>
            <?php endif; ?>

            <?php if (!$filtered || $sections['core']['shown'] !== []) : ?>
                <?php $open('alfaApiCore', $this->escape(Text::_('COM_ALFA_TOOLS_API_ROUTES_CORE_SECTION')) . $count(\count($sections['core']['shown']), $sections['core']['all']), $filtered); ?>
                <?php if ($sections['core']['shown'] === []) : ?>
                    <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_CORE_NONE'); ?></p>
                <?php else : ?>
                    <?php $endpoints($sections['core']['shown'], 'alfaApiAddresses', 'core'); ?>
                <?php endif; ?>
                <?php $close(); ?>
            <?php endif; ?>

            <?php $shownExtensions = 0; ?>
            <?php foreach ($sections['extensions'] as $extension) : ?>
                <?php $shownExtensions += \count($extension['shown']); ?>
            <?php endforeach; ?>
            <?php $extensionsAll = array_sum(array_column($sections['extensions'], 'all')); ?>
            <div class="mt-2">
                <?php $open('alfaApiExtensions', $this->escape(Text::_('COM_ALFA_TOOLS_API_ROUTES_EXT_SECTION')) . $count($shownExtensions, $extensionsAll), $filtered && $shownExtensions > 0); ?>
                <?php if ($sections['extensions'] === []) : ?>
                    <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_EXT_NONE'); ?></p>
                <?php endif; ?>
                <?php foreach ($sections['extensions'] as $extension) : ?>
                    <?php if ($filtered && $extension['shown'] === [] && $sourceFilter !== $extension['source']) : ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <?php
                    $title = $this->escape($extension['name']) . $count(\count($extension['shown']), $extension['all']);

                    if ($extension['refused'] !== []) {
                        $title .= '<span class="badge bg-warning text-dark ms-2">' . Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_REFUSED_COUNT', \count($extension['refused'])) . '</span>';
                    }

                    if ($extension['unavailable'] !== '') {
                        $title .= '<span class="badge bg-danger ms-2">' . Text::_('COM_ALFA_TOOLS_API_ROUTES_UNAVAILABLE_BADGE') . '</span>';
                    }
                    ?>
                    <div class="mb-2">
                        <?php $open('alfaApiExt-' . preg_replace('/[^a-z0-9_-]/', '-', substr($extension['source'], \strlen(ApiAddressListing::SOURCE_PLUGIN))), $title, $filtered && $extension['shown'] !== []); ?>
                        <?php if ($extension['unavailable'] !== '') : ?>
                            <p class="alert alert-warning"><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_UNAVAILABLE', $this->escape(AlfaPluginHelper::statePhrase($extension['unavailable']))); ?></p>
                        <?php endif; ?>
                        <?php if ($extension['shown'] !== []) : ?>
                            <?php $endpoints($extension['shown'], 'alfaApiExt-' . $extension['source'], $extension['source']); ?>
                        <?php elseif ($extension['unavailable'] === '') : ?>
                            <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_EXT_NO_ENDPOINT'); ?></p>
                        <?php endif; ?>
                        <?php if ($extension['refused'] !== []) : ?>
                            <h3 class="h6 mt-3"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_REFUSED'); ?></h3>
                            <ul class="small mb-0" data-refused="<?php echo $this->escape($extension['source']); ?>">
                                <?php foreach ($extension['refused'] as $refusal) : ?>
                                    <li><code><?php echo $this->escape($refusal->methods . ' ' . $refusal->pattern); ?></code>: <?php echo $this->escape($refusal->rule . ', ' . $refusal->detail); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php $close(); ?>
                    </div>
                <?php endforeach; ?>
                <?php $close(); ?>
            </div>

            <div class="mt-2">
                <?php $open('alfaApiInternals', $this->escape(Text::_('COM_ALFA_TOOLS_API_ROUTES_INTERNALS')) . '<span class="badge text-bg-info ms-2">' . Text::_('COM_ALFA_TOOLS_API_ROUTES_INTERNALS_BADGE') . '</span>', false); ?>
                <h3 class="h6"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_TITLE'); ?></h3>
                <p id="alfaApiTokenGroups">
                    <?php if ($routes['tokenGroups']['every']) : ?>
                        <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_GROUPS_EVERY'); ?>
                    <?php else : ?>
                        <?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_TOKEN_GROUPS', $this->escape(implode(', ', $routes['tokenGroups']['titles']))); ?>
                    <?php endif; ?>
                </p>
                <p class="small text-muted"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_RULE'); ?></p>

                <h3 class="h6 mt-3"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_HOLDERS_TITLE'); ?></h3>
                <?php if ($routes['holders'] === []) : ?>
                    <p><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_HOLDERS_NONE'); ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                        <table class="table table-sm" id="alfaApiHolders">
                            <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_HOLDERS_TITLE'); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PERMISSION'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ENDPOINTS'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_GROUPS'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ACCOUNTS'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($routes['holders'] as $holder) : ?>
                                    <tr data-permissions="<?php echo $this->escape(implode(' ', $holder['permissions'])); ?>">
                                        <td>
                                            <?php foreach ($holder['permissions'] as $name) : ?>
                                                <div>
                                                    <?php if (($routes['titles'][$name] ?? '') !== '') : ?>
                                                        <?php echo $this->escape($routes['titles'][$name]); ?><br>
                                                    <?php endif; ?>
                                                    <code><?php echo $this->escape($name); ?></code>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?php echo (int) $holder['endpoints']; ?></td>
                                        <td>
                                            <?php if ($holder['groups'] === []) : ?>
                                                <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_GROUP'); ?>
                                            <?php else : ?>
                                                <ul class="list-unstyled small mb-0">
                                                    <?php foreach ($holder['groups'] as $group) : ?>
                                                        <li>
                                                            <?php echo $this->escape($group['title']); ?>
                                                            <?php if ($group['superUsers']) : ?>
                                                                (<?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SUPER_USERS'); ?>)
                                                            <?php endif; ?>
                                                            <br>
                                                            <span class="badge <?php echo $group['apiLogin'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo Text::_($group['apiLogin'] ? 'COM_ALFA_TOOLS_API_ROUTES_API_LOGIN_YES' : 'COM_ALFA_TOOLS_API_ROUTES_API_LOGIN_NO'); ?></span>
                                                            <span class="badge <?php echo $group['tokenGroup'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo Text::_($group['tokenGroup'] ? 'COM_ALFA_TOOLS_API_ROUTES_TOKEN_ALLOWED_YES' : 'COM_ALFA_TOOLS_API_ROUTES_TOKEN_ALLOWED_NO'); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($holder['accounts'] === []) : ?>
                                                <span class="small"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_TOKEN'); ?></span>
                                            <?php else : ?>
                                                <ul class="list-unstyled small mb-0">
                                                    <?php foreach ($holder['accounts'] as $account) : ?>
                                                        <li>
                                                            <?php echo $this->escape($account['name'] . ' (' . $account['username'] . ')'); ?>
                                                            <?php if ($account['superUser']) : ?>
                                                                <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SUPER_USER'); ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 class="h6 mt-3"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_FORMATS_TITLE'); ?></h3>
                <p><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_FORMATS_CORE'); ?></p>
                <?php $formats = array_values(array_filter($routes['shown'], static fn (ApiAddress $address): bool => $address->fromPlugin())); ?>
                <?php if ($formats !== []) : ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" id="alfaApiFormats">
                            <caption class="visually-hidden"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_FORMATS_TITLE'); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SOURCE'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_METHOD'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ADDRESS'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_FORMAT'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_BODY_LIMIT'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($formats as $address) : ?>
                                    <tr>
                                        <td><?php echo $this->escape($address->sourceName); ?></td>
                                        <td><?php echo $this->escape(implode(', ', $address->methods)); ?></td>
                                        <td><code><?php echo $this->escape($address->address); ?></code></td>
                                        <td><code><?php echo $this->escape($address->mediaType); ?></code></td>
                                        <td><?php echo $address->maxBody === null ? '' : $this->escape(HTMLHelper::_('number.bytes', $address->maxBody)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php $close(); ?>
            </div>
        <?php endif; ?>
    </div>
</div>
