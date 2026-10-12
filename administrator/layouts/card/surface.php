<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One surface of one screen: the row, and every card in it drawn by the layout that
 * card named, from its own extension's folder when it shipped one. This file knows
 * no card and no contributor; that is what makes placement a declaration.
 *
 * @var array $displayData ['screen' => string, 'surface' => string, 'class' => string, 'cards' => array[]]
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;

$cards   = (array) ($displayData['cards'] ?? []);
$screen  = (string) ($displayData['screen'] ?? '');
$surface = (string) ($displayData['surface'] ?? '');
$class   = (string) ($displayData['class'] ?? '');

if ($cards === []) {
    return;
}

?>
<section class="alfa-cards <?php echo htmlspecialchars($class, ENT_QUOTES, 'UTF-8'); ?>"
    data-screen="<?php echo htmlspecialchars($screen, ENT_QUOTES, 'UTF-8'); ?>"
    data-surface="<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>">

    <?php foreach ($cards as $card) : ?>
        <?php
        $html = LayoutHelper::render($card['layout'], $card, $card['layout_base']);

        if (trim($html) === '') {
            // A layout that renders nothing is a contributor's mistake, not an empty
            // card: say so once rather than leaving a hole nobody can account for.
            Log::add(
                entry:    'CardRegistry: card ' . ($card['owner_group'] ?? '?') . '/' . ($card['owner_name'] ?? '?')
                    . ':' . ($card['id'] ?? '?') . ' named layout "' . ($card['layout'] ?? '') . '" which rendered nothing.',
                priority: Log::WARNING,
                category: 'com_alfa.cards',
            );

            continue;
        }
        ?>
        <?php // The width is a percentage the card asked for; the grid does the arithmetic. ?>
        <div class="alfa-card" style="--alfa-card-width: <?php echo (int) $card['width']; ?>%"
            data-card="<?php echo htmlspecialchars($card['id'], ENT_QUOTES, 'UTF-8'); ?>"
            data-width="<?php echo (int) $card['width']; ?>"
            data-owner="<?php echo htmlspecialchars($card['owner_group'] . ':' . $card['owner_name'], ENT_QUOTES, 'UTF-8'); ?>"
            data-ordering="<?php echo (int) $card['ordering']; ?>">
            <?php echo $html; ?>
        </div>
    <?php endforeach; ?>
</section>
