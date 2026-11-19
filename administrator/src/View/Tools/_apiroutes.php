<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The card of the REST API's addresses and who may call them (2.2.2), on Tools, Security: one table, one row an
 * address, core's and the plugins', with Joomla's search tools over it; the declarations core refused and the plugins
 * that cannot be loaded under it.
 *
 * Rendered from {@see HtmlView::display()} through a fixed-path include, as the integrity verdict is, so a template
 * override cannot hide an address, a permission or an account that holds a token. Read only.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tools\HtmlView $this
 */

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$routes = $this->apiRoutes;
$summary = $routes['summary'];
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
                <?php /* A GET form: the filters are in the address, and #integrity opens this tab after it is sent. */ ?>
                <form action="<?php echo Route::_('index.php?option=com_alfa&view=tools'); ?>#integrity" method="get" id="alfaApiRoutesForm">
                    <input type="hidden" name="option" value="com_alfa">
                    <input type="hidden" name="view" value="tools">
                    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this, 'options' => ['formSelector' => '#alfaApiRoutesForm']]); ?>
                </form>
            <?php endif; ?>

            <?php if ($routes['filtered']) : ?>
                <p class="small"><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_SHOWN', \count($routes['shown']), $summary['addresses']); ?></p>
            <?php endif; ?>

            <?php if ($routes['shown'] === []) : ?>
                <div class="alert alert-info">
                    <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                    <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_MATCH'); ?>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-sm" id="alfaApiAddresses">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TITLE'); ?>,
                            <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SOURCE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_METHOD'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ADDRESS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PERMISSION'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_GROUPS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ACCOUNTS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routes['shown'] as $address) : ?>
                                <tr>
                                    <td><?php echo $this->escape($address->sourceName); ?></td>
                                    <td><?php echo $this->escape(implode(', ', $address->methods)); ?></td>
                                    <td><code><?php echo $this->escape($address->address); ?></code></td>
                                    <td>
                                        <?php if (!$address->declared) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_PERMISSION'); ?>
                                        <?php endif; ?>
                                        <?php if ($address->perRecord) : ?>
                                            <div><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PER_RECORD'); ?></div>
                                        <?php endif; ?>
                                        <?php foreach ($address->permissions as $permission) : ?>
                                            <div>
                                                <?php if (($routes['titles'][$permission] ?? '') !== '') : ?>
                                                    <?php echo $this->escape($routes['titles'][$permission]); ?><br>
                                                <?php endif; ?>
                                                <code><?php echo $this->escape($permission); ?></code>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php if (!$address->declared) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_PERMISSION'); ?>
                                        <?php elseif ($address->perRecord) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PER_RECORD'); ?>
                                        <?php elseif ($address->groups === []) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_GROUP'); ?>
                                        <?php else : ?>
                                            <ul class="list-unstyled small mb-0">
                                                <?php foreach ($address->groups as $group) : ?>
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
                                        <?php if (!$address->declared) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_PERMISSION'); ?>
                                        <?php elseif ($address->perRecord) : ?>
                                            <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PER_RECORD'); ?>
                                        <?php elseif ($address->accounts === []) : ?>
                                            <span class="small"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_TOKEN'); ?></span>
                                        <?php else : ?>
                                            <ul class="list-unstyled small mb-0">
                                                <?php foreach ($address->accounts as $account) : ?>
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

            <?php if ($routes['refused'] !== []) : ?>
                <h3 class="h6 mt-3"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_REFUSED'); ?></h3>
                <ul class="small">
                    <?php foreach ($routes['refused'] as $entry) : ?>
                        <li><?php echo $this->escape($entry['name']); ?>: <code><?php echo $this->escape($entry['refusal']->methods . ' ' . $entry['refusal']->pattern); ?></code>:
                            <?php echo $this->escape($entry['refusal']->rule . ', ' . $entry['refusal']->detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($routes['unavailable'] !== []) : ?>
                <h3 class="h6 mt-3"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_UNAVAILABLE_TITLE'); ?></h3>
                <ul class="small">
                    <?php foreach ($routes['unavailable'] as $entry) : ?>
                        <li><?php echo $this->escape($entry['name']); ?>: <?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_UNAVAILABLE', $this->escape(AlfaPluginHelper::statePhrase($entry['reason']))); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
