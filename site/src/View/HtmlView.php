<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View;

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * View class for a list of Alfa.
 *
 * @since  1.0.1
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Render the view wrapped in the #alfa-app container element tagged with the lowercased view name.
     *
     * @param   string|null  $tpl  The name of the template file to parse.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function display($tpl = null)
    {
        $viewName = strtolower($this->getName());

        echo '<div id="alfa-app" data-view="' . htmlspecialchars($viewName) . '">';

        parent::display($tpl);

        echo '</div>';
    }
}
