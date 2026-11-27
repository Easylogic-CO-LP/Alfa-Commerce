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
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use DomainException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * The actions of the Background screen's Outgoing notifications tab: a consumer's changes given up put back in the
 * queue or discarded, a consumer paused or resumed, and finished deliveries deleted. Each a POST with the form token,
 * by staff holding alfa.tools.
 *
 * @since  2.3.0
 */
class OutboundController extends BaseController
{
    /**
     * Where every action returns to: the outgoing notifications screen.
     *
     * @since  2.3.0
     */
    private const PAGE = 'index.php?option=com_alfa&view=outbound';

    /**
     * Delete the finished deliveries checked in the list: taken, discarded, or of a plugin that takes none; a row in
     * any other state is kept and the message says so.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.3.0
     */
    public function delete(): void
    {
        $page = $this->page();

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $ids = array_map('intval', (array) $this->input->post->get('cid', [], 'array'));

        try {
            $result = OutboundConsumers::deleteFinished(ids: $ids);
        } catch (Throwable $e) {
            Log::add('Background (delete) on deliveries stopped with ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
            $this->setRedirect($page, Text::_('COM_ALFA_TASKS_ACTION_ERROR'), 'error');

            return;
        }

        $message = Text::plural('COM_ALFA_OUTBOUND_DELETED', $result['deleted']);

        if ($result['kept'] > 0) {
            $message .= ' ' . Text::plural('COM_ALFA_OUTBOUND_DELETE_KEPT', $result['kept']);
        }

        $this->setRedirect($page, $message, $result['kept'] > 0 ? 'warning' : 'success');
    }

    /**
     * Put the consumer's changes given up back in the queue, a batch at a time: the message says how many were put
     * back and whether more remain for the next Retry.
     *
     * @since  2.3.0
     */
    public function retry(): void
    {
        $this->act(call: static function (int $id): string {
            $result = OutboundConsumers::retryDead(id: $id);
            $message = Text::plural('COM_ALFA_OUTBOUND_RETRIED', $result['back']);

            if ($result['overtaken'] > 0) {
                $message .= ' ' . Text::plural('COM_ALFA_OUTBOUND_OVERTAKEN', $result['overtaken']);
            }

            return $result['more']
                ? $message . ' ' . Text::sprintf('COM_ALFA_OUTBOUND_RETRY_MORE', OutboundConsumers::RETRY_BATCH)
                : $message;
        });
    }

    /**
     * Discard the consumer's changes given up.
     *
     * @since  2.3.0
     */
    public function discard(): void
    {
        $this->act(call: static fn (int $id): string => Text::plural('COM_ALFA_OUTBOUND_DISCARDED', OutboundConsumers::discardDead(id: $id)));
    }

    /**
     * Pause the consumer.
     *
     * @since  2.3.0
     */
    public function pause(): void
    {
        $this->act(call: static function (int $id): string {
            OutboundConsumers::setDelivering(id: $id, delivering: false);

            return Text::_('COM_ALFA_OUTBOUND_PAUSED_DONE');
        });
    }

    /**
     * Resume the consumer.
     *
     * @since  2.3.0
     */
    public function resume(): void
    {
        $this->act(call: static function (int $id): string {
            OutboundConsumers::setDelivering(id: $id, delivering: true);

            return Text::_('COM_ALFA_OUTBOUND_RESUMED_DONE');
        });
    }

    /**
     * One action: the token and the permission checked, the consumer's id read, the outcome said on the Background
     * screen.
     *
     * @param callable(int): string $call The action, given the consumer's id.
     *
     * @since  2.3.0
     */
    private function act(callable $call): void
    {
        $page = $this->page();

        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($page, Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $id = RequestScalar::int($this->input->post, 'id');

        try {
            $this->setRedirect($page, $call($id), 'success');
        } catch (DomainException) {
            $this->setRedirect($page, Text::_('COM_ALFA_OUTBOUND_GONE'), 'warning');
        } catch (Throwable $e) {
            Log::add('Background (' . $this->getTask() . ') on consumer #' . $id . ' stopped with ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, OutboundFeed::LOG);
            $this->setRedirect($page, Text::_('COM_ALFA_TASKS_ACTION_ERROR'), 'error');
        }
    }

    /**
     * The screen an action returns to, with the page the screen was opened from when the action's form carried it
     * on, so the screen's way back stays.
     *
     * @since  2.3.0
     */
    private function page(): string
    {
        $return = AlfaHelper::returnAddress($this->input);

        return Route::_(self::PAGE . ($return === '' ? '' : '&return=' . rawurlencode(base64_encode($return))), false);
    }
}
