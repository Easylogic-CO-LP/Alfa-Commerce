<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A card that lists orders rather than counting them. The status pill wears the
 * colours the status itself carries, so the dashboard reads like the order list.
 *
 * @var array $displayData One resolved card: title, url, data[]
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$rows = (array) ($displayData['data']['rows'] ?? []);
?>
<span class="alfa-card-head">
    <span class="alfa-card-title"><?php echo Text::_($displayData['title']); ?></span>

    <?php if (($displayData['url'] ?? '') !== '') : ?>
        <a class="alfa-card-more" href="<?php echo htmlspecialchars($displayData['url'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo Text::_('COM_ALFA_DASHCARD_SEE_ALL'); ?>
        </a>
    <?php endif; ?>
</span>

<?php if ($rows === []) : ?>
    <p class="alfa-card-empty"><?php echo Text::_('COM_ALFA_DASHCARD_LATEST_EMPTY'); ?></p>
<?php else : ?>
    <ul class="alfa-card-orders">
        <?php foreach ($rows as $row) : ?>
            <li class="alfa-card-order">
                <?php // The provider blanks the url when the viewer may not edit orders. ?>
                <?php if (($row['url'] ?? '') !== '') : ?>
                    <a class="alfa-card-order-number" href="<?php echo htmlspecialchars($row['url'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($row['number'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php else : ?>
                    <span class="alfa-card-order-number">
                        <?php echo htmlspecialchars($row['number'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
                <span class="alfa-card-order-customer"><?php echo htmlspecialchars($row['customer'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="alfa-card-order-date"><?php echo HTMLHelper::_('date', $row['created'], Text::_('DATE_FORMAT_LC5')); ?></span>

                <?php if ($row['status'] !== '') : ?>
                    <span class="alfa-card-order-status"
                        <?php if ($row['bg_color'] !== '') : ?>
                            style="background: <?php echo htmlspecialchars($row['bg_color'], ENT_QUOTES, 'UTF-8'); ?>; color: <?php echo htmlspecialchars($row['color'] ?: '#fff', ENT_QUOTES, 'UTF-8'); ?>"
                        <?php endif; ?>>
                        <?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
