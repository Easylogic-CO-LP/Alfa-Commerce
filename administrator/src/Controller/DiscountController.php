<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFailedCopyRedirect;
use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountReach;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\DiscountReachJob;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerTrigger;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Shipment controller class.
 *
 * @since  1.0.0
 */
class DiscountController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;

    protected $view_list = 'discounts';

    /**
     * The Reach card of a saved rule, asked for by the form on demand, as JSON. The estimate decides (DiscountReach::
     * estimate()): within the threshold the reach is counted now and the answer is {state: done, html}; above it the
     * rule's job is queued, due now, and stepped here the way the Background screen steps a job (one tick on it), the
     * answer {state: working, percent, retry_after} until it ends, then {state: done, html} from the stored reach. A
     * POST with the form token, by a user who may edit discounts.
     *
     * @since  2.3.0
     */
    public function reach(): void
    {
        $this->app->setHeader('Content-Type', 'application/json', true);

        if (strtoupper($this->input->getMethod()) !== 'POST' || !Session::checkToken('post')) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $this->app->close();
        }

        if (!AlfaHelper::can(section: 'discounts', action: 'edit')) {
            echo new JsonResponse(null, Text::_('JERROR_ALERTNOAUTHOR'), true);
            $this->app->close();
        }

        $id = RequestScalar::int($this->input->post, 'id');
        $reach = new DiscountReach(db: Factory::getContainer()->get(DatabaseInterface::class));
        $counts = static fn (array $result): string => LayoutHelper::render('discount.reach_counts', ['reach' => $result], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');

        try {
            if ($id <= 0) {
                throw new \RuntimeException('no saved rule');
            }

            // A job of this rule waiting or running: stepped, then answered as it stands.
            $job = $this->reachJob(id: $id);

            if ($job !== null) {
                echo new JsonResponse($this->reachStep(id: $id, jobId: (int) $job['id'], reach: $reach, counts: $counts));
                $this->app->close();
            }

            $estimate = $reach->estimate(discountId: $id);

            if ($estimate['now']) {
                $result = $reach->of(discountId: $id);

                if ($result === null) {
                    throw new \RuntimeException('the rule is gone');
                }

                $reach->store(discountId: $id, reach: $result);
                echo new JsonResponse(['state' => 'done', 'html' => $counts($result)]);
                $this->app->close();
            }

            $queued = DiscountReachJob::enqueueFor(discountId: $id);
            echo new JsonResponse($this->reachStep(id: $id, jobId: $queued->id, reach: $reach, counts: $counts));
            $this->app->close();
        } catch (Throwable $e) {
            Log::add('Discount #' . $id . ': the Reach card could not be counted: ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');
            echo new JsonResponse(null, Text::_('COM_ALFA_DISCOUNT_REACH_FAILED'), true);
            $this->app->close();
        }
    }

    /**
     * The rule's reach job waiting or running, as the staff see it; null when none.
     *
     * @param int $id The rule.
     *
     * @since  2.3.0
     */
    private function reachJob(int $id): ?array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $jobId = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('active_key') . ' = ' . $db->quote(DiscountReachJob::scopeFor(discountId: $id)))
                ->order($db->quoteName('id') . ' DESC'),
            0,
            1,
        )->loadResult();

        return $jobId > 0 ? TaskStaff::job(id: $jobId) : null;
    }

    /**
     * One step of the rule's job, as the Background screen steps one: due now, one tick on it alone, then the answer:
     * done with the stored reach's markup, or working with how far it is and when to ask again.
     *
     * @param int $id The rule.
     * @param int $jobId The job.
     * @param DiscountReach $reach The service.
     * @param callable $counts The counts' markup, from a reach.
     *
     * @return array{state: string, html?: string, percent?: int, retry_after?: int}
     *
     * @since  2.3.0
     */
    private function reachStep(int $id, int $jobId, DiscountReach $reach, callable $counts): array
    {
        TaskStaff::dueNow(id: $jobId);
        RunnerTrigger::closeSession();
        $tick = Runner::tick(trigger: 'progress', budgetMs: Runner::PROCESS_MS, onlyJob: $jobId);
        $job = TaskStaff::job(id: $jobId);
        $waiting = $job !== null && \in_array((string) $job['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true);

        if (!$waiting) {
            $stored = $reach->stored(discountId: $id);

            if ($stored === null) {
                throw new \RuntimeException('the job ended with nothing stored' . ($job !== null && $job['error'] !== '' ? ': ' . Tasks::errorText($job['error']) : ''));
            }

            return ['state' => 'done', 'html' => $counts($stored)];
        }

        // The tick worked the job: ask again at once; it could not (another tick holds it, the runner is paused): the
        // endpoint's gap.
        return ['state' => 'working', 'percent' => (int) ($job['percent'] ?? 0), 'retry_after' => $tick->chunks > 0 ? 0 : Runner::ENDPOINT_GAP_SECONDS];
    }
}
