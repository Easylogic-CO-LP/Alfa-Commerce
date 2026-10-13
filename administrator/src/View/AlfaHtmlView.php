<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View;

use Alfa\Component\Alfa\Administrator\Model\AlfaListModel;

\defined('_JEXEC') or die;

/**
 * The base for a com_alfa admin GRID view on the extension contract: the shared
 * {@see HtmlView} (so the alfa-app wrapper stays exactly where it was) plus the toolbar, column,
 * action and render-slot plumbing.
 *
 *     final class HtmlView extends AlfaHtmlView
 *     {
 *         protected string $alfaEntity = 'currency';
 *         // ...everything else unchanged...
 *     }
 *
 * The 23 grids already on the shared base change one word. The 10 that extend Joomla's HtmlView
 * directly do NOT belong here: they emit no wrapper today and moving them onto this base would
 * add one; those use {@see HasAdminEvents} directly and keep their parent.
 *
 * Adds nothing to the output on its own: with no plugin subscribed, every phase this base
 * introduces short-circuits before it builds anything.
 *
 * @since  2.0.0
 */
abstract class AlfaHtmlView extends HtmlView
{
    use HasAdminEvents;

    /**
     * Whether this grid is being rendered inside a modal.
     *
     * @since  2.0.0
     */
    public bool $isModal = false;

    /**
     * Keep a modal grid inside its frame.
     *
     * Pagination links are plain URLs the CMS builds from scratch, so without
     * these two params the first page click escapes the iframe into the full
     * admin. Keeping the modal's STATE apart is a separate concern, handled once
     * in {@see AlfaListModel::populateState()}.
     *
     * @param string|null $tpl The layout.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function display($tpl = null)
    {
        $this->isModal = AlfaListModel::isModalRequest();

        if ($this->isModal && !empty($this->pagination)) {
            $this->pagination->setAdditionalUrlParam('tmpl', 'component');
            $this->pagination->setAdditionalUrlParam('modal', '1');
        }

        return parent::display($tpl);
    }
}
