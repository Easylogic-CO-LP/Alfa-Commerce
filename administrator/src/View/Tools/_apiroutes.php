<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The card of the addresses extensions add to the REST API (2.2.2, P3-445), on Tools, Security.
 *
 * Rendered from {@see HtmlView::display()} through a fixed-path include, as the integrity verdict is, so a template
 * override cannot hide a route, a permission or an account that holds a token. Read only.
 *
 * @var \Alfa\Component\Alfa\Administrator\View\Tools\HtmlView $this
 */

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

$routes = $this->apiRoutes;
$base = rtrim(Uri::root(), '/') . '/api/index.php/';
?>
<div class="card mb-3">
    <div class="card-header"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TITLE'); ?></div>
    <div class="card-body">
        <p class="small text-muted"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_DESC'); ?></p>

        <?php if ($routes['unreadable']) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_UNREADABLE'); ?></p>
        <?php elseif ($routes['plugins'] === []) : ?>
            <p class="mb-0"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NONE'); ?></p>
        <?php else : ?>
            <?php if (!$routes['tokenPlugin']) : ?>
                <p class="alert alert-warning"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_PLUGIN_OFF'); ?></p>
            <?php endif; ?>

            <?php foreach ($routes['plugins'] as $plugin) : ?>
                <h3 class="h6 mt-3"><?php echo $this->escape($plugin['name']); ?></h3>

                <?php if ($plugin['unavailable'] !== '') : ?>
                    <p class="text-danger"><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_UNAVAILABLE', $this->escape(AlfaPluginHelper::statePhrase($plugin['unavailable']))); ?></p>
                    <?php continue; ?>
                <?php endif; ?>

                <?php if ($plugin['accepted'] !== []) : ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_METHOD'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_ADDRESS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_PERMISSION'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SIGN_IN'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugin['accepted'] as $declaration) : ?>
                                <?php $title = (string) ($routes['permissions'][$declaration->permission]['title'] ?? ''); ?>
                                <tr>
                                    <td><?php echo $this->escape(implode(', ', $declaration->methods)); ?></td>
                                    <td><code><?php echo $this->escape($base . $declaration->pattern); ?></code></td>
                                    <td>
                                        <?php if ($title !== '') : ?>
                                            <?php echo $this->escape($title); ?><br>
                                        <?php endif; ?>
                                        <code><?php echo $this->escape($declaration->permission); ?></code>
                                    </td>
                                    <td><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($plugin['refused'] !== []) : ?>
                    <p class="mb-1"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_REFUSED'); ?></p>
                    <ul class="small">
                        <?php foreach ($plugin['refused'] as $refusal) : ?>
                            <li><code><?php echo $this->escape($refusal->methods . ' ' . $refusal->pattern); ?></code>:
                                <?php echo $this->escape($refusal->rule . ', ' . $refusal->detail); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php foreach ($routes['permissions'] as $name => $permission) : ?>
                <h3 class="h6 mt-3"><?php echo Text::sprintf('COM_ALFA_TOOLS_API_ROUTES_WHO', $this->escape($permission['title'] !== '' ? $permission['title'] : $name)); ?></h3>
                <?php if ($permission['groups'] === []) : ?>
                    <p class="small"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_GROUP'); ?></p>
                <?php else : ?>
                    <ul class="small">
                        <?php foreach ($permission['groups'] as $group) : ?>
                            <li>
                                <?php echo $this->escape($group['title']); ?>
                                <?php if ($group['superUsers']) : ?>
                                    (<?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SUPER_USERS'); ?>)
                                <?php endif; ?>:
                                <?php echo Text::_($group['apiLogin'] ? 'COM_ALFA_TOOLS_API_ROUTES_API_LOGIN_YES' : 'COM_ALFA_TOOLS_API_ROUTES_API_LOGIN_NO'); ?>;
                                <?php echo Text::_($group['tokenAllowed'] ? 'COM_ALFA_TOOLS_API_ROUTES_TOKEN_ALLOWED_YES' : 'COM_ALFA_TOOLS_API_ROUTES_TOKEN_ALLOWED_NO'); ?>.
                                <?php if ($group['accounts'] === []) : ?>
                                    <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_NO_TOKEN'); ?>
                                <?php else : ?>
                                    <?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_TOKEN_HOLDERS'); ?>
                                    <?php foreach ($group['accounts'] as $i => $account) : ?>
                                        <?php echo $i > 0 ? ', ' : ''; ?><?php echo $this->escape($account['name'] . ' (' . $account['username'] . ')'); ?>
                                        <?php if ($account['superUser']) : ?>
                                            <span class="badge bg-warning text-dark"><?php echo Text::_('COM_ALFA_TOOLS_API_ROUTES_SUPER_USER'); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
