<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\DocumentTypes\DocumentTypeRegistry;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

/**
 * Document types list controller (publish/unpublish/ordering/delete
 * tasks; deletion is further guarded by the model: never for core
 * rows, never while referenced).
 *
 * @since  1.3.0
 */
class DocumenttypesController extends AdminController
{
    /**
     * Proxy to the edit model.
     *
     * @param string $name Model name.
     * @param string $prefix Model prefix.
     * @param array $config Configuration array.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @since  1.3.0
     */
    public function getModel($name = 'Documenttype', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Discover and run every document type provider (the package
     * extension point). Idempotent: a package's install script may
     * trigger the same sync. Writes type rows, so it needs the documents edit permission.
     *
     *
     * @since  1.3.0
     */
    public function syncProviders(): void
    {
        $this->checkToken();

        if (!AlfaHelper::can(section: 'documents', action: 'edit', user: $this->app->getIdentity())) {
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=documenttypes', false));

            return;
        }

        $summary = (new DocumentTypeRegistry())->syncProviders();

        $this->setMessage(Text::sprintf(
            'COM_ALFA_DOCUMENTTYPES_SYNC_RESULT',
            \count($summary['registered']),
            \count($summary['errors']),
        ));

        foreach ($summary['errors'] as $element => $message) {
            $this->app->enqueueMessage($element . ': ' . $message, 'warning');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=documenttypes', false));
    }
}
