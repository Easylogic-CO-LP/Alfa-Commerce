<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Search engine picker, built from whatever is actually installed.
 *
 * The list is the registry's, never a hardcoded set of keys: install an engine
 * plugin and it appears here, disable it and it disappears, with no core change.
 * That is the merchant-facing half of the engine contract: without it, engine
 * selection exists in code and is unreachable in the UI.
 *
 * An engine that is installed but NOT ready (no credentials, never rebuilt, service
 * unreachable) is still offered, and labelled as such. Hiding it would be worse: the
 * merchant could not select the engine they are in the middle of setting up, and
 * would have no way to see why the one they chose is not being used.
 *
 * @since  2.0.0
 */
class SearchEngineField extends ListField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'SearchEngine';

    /**
     * Build the option list from the registry.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    protected function getOptions(): array
    {
        $options = [];
        $registry = new SearchEngineRegistry();

        foreach ($registry->all() as $key => $engine) {
            $label = $engine->label();

            if (!$engine->isReady()) {
                $label .= ' - ' . Text::_('COM_ALFA_CONFIG_SEARCH_ENGINE_NOT_READY');
            }

            $options[] = HTMLHelper::_('select.option', $key, $label);
        }

        return array_merge(parent::getOptions(), $options);
    }
}
