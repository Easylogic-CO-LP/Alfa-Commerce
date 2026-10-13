<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * An edit form's kept post: only for the record it was posted for.
 *
 * Joomla keeps an edit form's last post in `<option>.edit.<name>.data` after a
 * failed save and after every reload, and only the add, edit, cancel and a
 * successful save tasks clear it. A record opened any other way (a direct
 * link such as a Dashboard tile, the Back button, a second tab) still finds
 * that post, and it used to fill WHICHEVER record was on screen: item B showed
 * item A's name, SKU, prices and id, and Save wrote them into B, because the
 * form posts to B's URL. A post belongs to the id it was posted with: it
 * refills that record and no other.
 *
 * Every edit model's loadFormData() and every field that overlays the post
 * read it here. OrderModel::keptPostFor() applies the same rule.
 *
 * @since  2.0.0
 */
final class KeptPost
{
    /**
     * The post kept under $stateKey, when it was posted for $recordId.
     *
     * @param string $stateKey The user-state key, e.g. com_alfa.edit.item.data.
     * @param int $recordId The record on screen; 0 for a new one.
     *
     * @return array The kept post, or [] when there is none for this record.
     *
     * @since  2.0.0
     */
    public static function forRecord(string $stateKey, int $recordId): array
    {
        $post = Factory::getApplication()->getUserState($stateKey, []);
        $post = \is_array($post) ? $post : (array) $post;

        return $post !== [] && (int) ($post['id'] ?? 0) === $recordId ? $post : [];
    }
}
