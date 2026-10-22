<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Resetdefaults;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\ResetToDefault;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools > Reset to default: every setting the reset would change, current value and default,
 * and the confirmation.
 *
 * @since  2.1.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * What the reset would change ({@see ResetToDefault::preview()}).
     *
     * @var array{options: list<array{key: string, current: mixed, default: mixed}>, groups: list<array<string, mixed>>}
     *
     * @since  2.1.0
     */
    public array $preview = ['options' => [], 'groups' => []];

    /**
     * The label of each Option, from the Options form, by key.
     *
     * @var array<string, string>
     *
     * @since  2.1.0
     */
    public array $labels = [];

    /**
     * Render the page.
     *
     * @param string|null $tpl The template name.
     *
     * @since  2.1.0
     */
    public function display($tpl = null): void
    {
        if (!ResetToDefault::permitted(Factory::getApplication()->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->preview = (new ResetToDefault())->preview();
        $this->labels = $this->labels();

        ToolbarHelper::title(Text::_('COM_ALFA_RESET_TITLE'), 'refresh');
        ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_alfa&view=tools');

        parent::display($tpl);
    }

    /**
     * The label of each field of the Options form, translated, by name.
     *
     * @return array<string, string>
     *
     * @since  2.1.0
     */
    private function labels(): array
    {
        $labels = [];
        $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_alfa/config.xml');

        if ($xml === false) {
            return $labels;
        }

        foreach ($xml->xpath('//field[@name]') ?: [] as $field) {
            $label = (string) ($field['label'] ?? '');

            if ($label !== '') {
                $labels[(string) $field['name']] = Text::_($label);
            }
        }

        return $labels;
    }
}
