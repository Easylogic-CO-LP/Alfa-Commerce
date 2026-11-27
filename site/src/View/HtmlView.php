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
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var string The page's class on the wrapper, what the page is (items-list-page, orders, ...), set by the view;
     *             '' for none. A template's own rules name it beside the wrapper's id.
     * @since  2.3.0
     */
    protected string $pageClass = '';

    /**
     * Render the view wrapped in the #alfa-app container: the one element of the storefront that carries the
     * component's name, the root every component rule is scoped under (:where(#alfa-app)), tagged with the page's
     * class and the lowercased view name.
     *
     * @param string|null $tpl The name of the template file to parse.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        $viewName = strtolower($this->getName());
        $class = $this->pageClass !== '' ? ' class="' . htmlspecialchars($this->pageClass, ENT_QUOTES, 'UTF-8') . '"' : '';

        echo '<div id="alfa-app"' . $class . ' data-view="' . htmlspecialchars($viewName) . '">';

        parent::display($tpl);

        echo '</div>';
    }
}
