<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The Alfa admin menu, rendered as hidden markup that {@see \Alfa\Component\Alfa\Administrator\Menu\MenuInjector}
 * loads on every com_alfa admin page. A small script relocates it INTO Joomla's native
 * #sidebarmenu, replacing the CMS nav while you are in Alfa (with a back arrow to return),
 * so it is genuine native chrome: Joomla's own classes, our own items, icons and
 * separators. Icons are a currentColor mask, so they match the sidebar theme.
 *
 * @var array $displayData
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/** @var array<int, array{label:string, items:\Alfa\Component\Alfa\Administrator\Menu\MenuItem[]}> $groups */
$groups = $displayData['groups'] ?? [];
$root   = Uri::root(true);
$e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$iconStyle = static fn (string $path): string => $path === '' ? ''
    : ' style="--alfa-icon:url(\'' . $e($root . '/' . ltrim($path, '/')) . '\')"';
?>
<style>#sidebarmenu ul.main-nav:not(.alfa-nav){display:none;}/* FOUC guard: hide CMS nav until the menu is placed */</style>

<?php // A stub placed into the CMS menu, so from Joomla's own nav you can jump into Alfa. ?>
<div data-alfa-back hidden>
    <li class="item item-level-1 alfa-jump">
        <a class="no-dropdown" href="<?php echo Route::_('index.php?option=com_alfa'); ?>" data-alfa-to-app aria-label="<?php echo $e(Text::_('COM_ALFA')); ?>">
            <span class="alfa-menu-icon"<?php echo $iconStyle('media/com_alfa/images/admin/menu/alfa.svg'); ?>></span>
            <span class="sidebar-item-title"><?php echo $e(Text::_('COM_ALFA')); ?></span>
            <span class="alfa-menu-arrow"<?php echo $iconStyle('media/com_alfa/images/admin/menu/forward.svg'); ?>></span>
        </a>
    </li>
</div>

<div data-alfa-sidebar hidden>
    <ul class="nav flex-column main-nav alfa-nav">
        <li class="item item-level-1 alfa-back">
            <a class="no-dropdown" href="#" data-alfa-to-joomla aria-label="<?php echo $e(Text::_('JTOOLBAR_BACK')); ?>">
                <span class="alfa-menu-icon"<?php echo $iconStyle('media/com_alfa/images/admin/menu/back.svg'); ?>></span>
                <span class="sidebar-item-title"><?php echo $e(Text::_('JTOOLBAR_BACK')); ?></span>
            </a>
        </li>

        <?php foreach ($groups as $group) : ?>
            <?php if ($group['label'] !== '') : ?>
                <li class="alfa-menu-header item-level-1" role="presentation">
                    <span><?php echo $e(Text::_($group['label'])); ?></span>
                </li>
            <?php endif; ?>
            <?php foreach ($group['items'] as $item) : ?>
                <li class="item item-level-1<?php echo $item->active ? ' current active' : ''; ?>">
                    <a class="no-dropdown<?php echo $item->active ? ' mm-active' : ''; ?>"
                       href="<?php echo Route::_($item->link); ?>"
                       aria-label="<?php echo $e(Text::_($item->title)); ?>">
                        <span class="alfa-menu-icon"<?php echo $iconStyle($item->icon); ?>></span>
                        <span class="sidebar-item-title"><?php echo $e(Text::_($item->title)); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </ul>
</div>
