<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The manufacturers as a grid of links: as many columns as the width holds. An entry shows its logo, its name or
 * both; an entry asked for its logo that has none shows its name, so no entry is ever empty. A logo alone carries the
 * name as its alternative text; beside the name it is decorative (an empty alt), so the name is read once.
 *
 * @var object $module The module instance.
 * @var \Joomla\Registry\Registry $params The module's settings.
 * @var list<object{id: int, name: string, link: string, logo: string}> $entries The entries, in order.
 * @var string $show logo_name, logo or name.
 */

\defined('_JEXEC') or die;

$moduleId = (int) $module->id;
$sfx = htmlspecialchars((string) $params->get('moduleclass_sfx', ''), ENT_QUOTES, 'UTF-8');
$withLogo = $show !== 'name';
?>
<div class="mod-alfa-manufacturers mod-alfa-manufacturers-<?php echo $moduleId; ?><?php echo $sfx !== '' ? ' ' . $sfx : ''; ?>">
    <ul class="list" data-show="<?php echo $show; ?>">
        <?php foreach ($entries as $entry) : ?>
            <?php $logo = $withLogo && $entry->logo !== ''; ?>
            <?php $name = htmlspecialchars($entry->name, ENT_QUOTES, 'UTF-8'); ?>
            <li class="entry">
                <a class="link" href="<?php echo htmlspecialchars($entry->link, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($logo) : ?>
                        <img class="logo" src="<?php echo htmlspecialchars($entry->logo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $show === 'logo' ? $name : ''; ?>" loading="lazy">
                    <?php endif; ?>
                    <?php if (!$logo || $show !== 'logo') : ?>
                        <span class="name"><?php echo $name; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
