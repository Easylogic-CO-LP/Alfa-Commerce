<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaManufacturers\Site\Dispatcher;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Module\AlfaManufacturers\Site\Helper\AlfaManufacturersHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher for mod_alfa_manufacturers.
 *
 * The entries are read before anything is registered: a module with nothing to show draws nothing and loads no
 * stylesheet.
 *
 * @since  2.3.0
 */
class Dispatcher extends AbstractModuleDispatcher
{
    /**
     * @var list<object> The entries, read once in dispatch() and handed to the layout.
     * @since 2.3.0
     */
    private array $entries = [];

    /**
     * Read the entries, register the stylesheet, then render.
     *
     * A failure to read is logged and the module draws nothing: one module never takes the page down with it.
     *
     * @since  2.3.0
     */
    public function dispatch(): void
    {
        try {
            $this->entries = AlfaManufacturersHelper::entries(params: new Registry($this->module->params));
        } catch (Throwable $e) {
            Log::add('mod_alfa_manufacturers could not read the manufacturers: ' . ErrorText::of($e), Log::ERROR, 'com_alfa');

            return;
        }

        if ($this->entries === []) {
            return;
        }

        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_manufacturers');
        $wa->useStyle('mod_alfa_manufacturers.manufacturers');

        parent::dispatch();
    }

    /**
     * The layout's data: the entries and what each shows.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();

        $data['entries'] = $this->entries;
        $data['show'] = AlfaManufacturersHelper::choice(params: $data['params'], name: 'show', choices: AlfaManufacturersHelper::SHOW);

        return $data;
    }
}
