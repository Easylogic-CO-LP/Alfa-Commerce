<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The background work's health card on the Dashboard's alert strip (2.2.0): the standing notice's text and a link to
 * Tools > Tasks. Nothing is printed while the notice does not stand.
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

<div class="alfa-attention">
    <a class="alfa-attention-tile" data-severity="warning" href="<?php echo $e((string) $data['url']); ?>">
        <span class="alfa-attention-text"><?php echo $e((string) $data['text']); ?></span>
        <span class="alfa-attention-go"><?php echo $e(Text::_('COM_ALFA_DASHCARD_RUNNER_HEALTH_GO')); ?></span>
    </a>
</div>
