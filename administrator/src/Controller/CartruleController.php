<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Throwable;

/**
 * Cart rule form controller.
 *
 * @since  2.0.0
 */
class CartruleController extends FormController
{
    use HasFormPermissions;

    /**
     * @var string The URL view list variable.
     * @since  2.0.0
     */
    protected $view_list = 'cartrules';

    /**
     * How many options one lookup may return.
     *
     * The point of the lookup is to stop shipping every option into the page;
     * an unbounded response would put the weight back. The customer types to
     * narrow; this is the ceiling per keystroke, not a catalogue limit.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const LOOKUP_LIMIT = 50;

    /**
     * Condition values for one match type, as the fancy-select expects them.
     *
     * Replaces rendering products + categories + manufacturers into every
     * condition row of every group (2,648 <option> tags / 154 KB on a modest
     * catalogue, of which a row uses at most one third). Now a row costs what
     * the merchant actually asked for.
     *
     * Response: [{value, text}], the shape joomla-field-fancy-select's
     * remote-search consumes verbatim.
     *
     *
     * @since  2.0.0
     */
    public function conditionValues(): void
    {
        $app = $this->app ?? Factory::getApplication();
        $app->allowCache(false);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        // Same gate as opening the form this feeds. No token: it is a read-only
        // lookup of data the user can already see in the list views, and a
        // token would expire while the form sits open.
        $user = $app->getIdentity();

        if (!AlfaHelper::can(section: 'cartrules', action: 'edit', user: $user)
            && !AlfaHelper::can(section: 'cartrules', action: 'create', user: $user)) {
            $app->setHeader('status', 403, true);
            $app->sendHeaders();
            echo json_encode(['error' => Text::_('JERROR_ALERTNOAUTHOR')]);
            $app->close();
        }

        $input = $app->getInput();
        $type = (string) $input->getCmd('type', 'categories');
        $term = (string) $input->getString('term', '');

        // The condition types are a closed set; anything else is a probe.
        $models = ['items' => 'items', 'categories' => 'categories', 'manufacturers' => 'manufacturers'];

        if (!isset($models[$type])) {
            $app->sendHeaders();
            echo json_encode([]);
            $app->close();
        }

        // The lookup returns names of unpublished records too, so the user must
        // also be allowed to list the section being searched.
        if (!AlfaHelper::can(section: $type, action: 'view', user: $user)) {
            $app->setHeader('status', 403, true);
            $app->sendHeaders();
            echo json_encode(['error' => Text::_('JERROR_ALERTNOAUTHOR')]);
            $app->close();
        }

        $options = [];

        try {
            $model = $this->getModel($models[$type], 'Administrator', ['ignore_request' => true]);

            if ($model) {
                // populateState() must run before the state is forced, or it
                // fires later inside getItems() and overwrites these.
                $model->getState('list.ordering');
                $model->setState('filter.state', '*');
                $model->setState('filter.search', $term);
                $model->setState('list.limit', self::LOOKUP_LIMIT);
                $model->setState('list.start', 0);

                foreach ($model->getItems() ?: [] as $row) {
                    $options[] = [
                        'value' => (string) ($row->id ?? ''),
                        'text' => (string) ($row->name ?? $row->title ?? $row->id ?? ''),
                    ];
                }
            }
        } catch (Throwable $e) {
            // A lookup that fails must not break the form; an empty list just
            // means "nothing to offer", and the merchant can still type.
            $app->setHeader('status', 500, true);
            $app->sendHeaders();
            echo json_encode([]);
            $app->close();
        }

        $app->sendHeaders();
        echo json_encode($options);
        $app->close();
    }
}
