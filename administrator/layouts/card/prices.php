<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The price check's card on the Dashboard's alert strip: one sentence with the counts of the kinds of finding
 * ("3 sales above the price, 1 rule published outside its period") and the way to the price check. Nothing is
 * printed when the sentence is empty: its provider answers no data while the check finds nothing, and the registry
 * places no card for it.
 *
 * @var array $displayData title, data {text, url}.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$data = (array) ($displayData['data'] ?? []);

if (($data['text'] ?? '') === '') {
    return;
}

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<span class="alfa-card-head">
    <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>
</span>

<div class="alfa-attention" data-alfa-prices-card>
    <a class="alfa-attention-tile" data-severity="warning" href="<?php echo $e((string) $data['url']); ?>">
        <span class="alfa-attention-text"><?php echo $e((string) $data['text']); ?></span>
        <span class="alfa-attention-go"><?php echo $e(Text::_('COM_ALFA_DASHCARD_PRICES_GO')); ?></span>
    </a>
</div>
