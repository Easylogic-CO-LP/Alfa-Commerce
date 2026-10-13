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
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Credit\CreditNoteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\ShowOn\SubmitAuthority;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * User controller class.
 *
 * @since  1.0.0
 */
class UserController extends FormController
{
    use HasFormPermissions;
    use HasFailedCopyRedirect;

    protected $view_list = 'users';

    /**
     * AJAX (admin user edit, User Info tab): save one saved-info row of the
     * customer. Same contract as the frontend screen: explicit edit merges
     * into an owned unfrozen row, anything else inserts.
     *
     *
     * @since  2.0.0
     */
    /**
     * Add credit to the profile's linked Joomla account (manual entry).
     *
     * The Credit tab's buttons repoint the user adminForm here via
     * formaction; the token rides along, jform is ignored.
     *
     *
     * @since  2.0.0
     */
    public function addCredit(): void
    {
        $this->applyCreditAction(direction: 'credit');
    }

    /**
     * Deduct credit (correction / fraud adjustment): guarded, the balance
     * can never go negative.
     *
     *
     * @since  2.0.0
     */
    public function deductCredit(): void
    {
        $this->applyCreditAction(direction: 'debit');
    }

    /**
     * Shared guard + ledger call for the two credit actions.
     *
     * The amount is read as typed, on its own digits, in the account's money
     * ({@see ExactMoney}): a figure with more decimal places than that money
     * holds is refused, never rounded.
     *
     * When nothing was recorded staff read why in plain words, escaped once:
     * this screen's own sentence for what it checks itself (no linked
     * account, a posted account that is not the profile's, an amount that is
     * not one or is finer than the account's money, the cap, no amount, no
     * reason), the ledger's plain reason for a debit of more than
     * the account holds, and this screen's own sentence for an entry that
     * could not be written. The ledger's messages carry its class name and
     * are the log's, never the screen's.
     *
     * @param string $direction 'credit' | 'debit'.
     *
     *
     * @since  2.0.0
     */
    private function applyCreditAction(string $direction): void
    {
        $this->checkToken();

        $app = Factory::getApplication();
        $profileId = $this->input->getInt('id', 0);
        $redirect = 'index.php?option=com_alfa&view=user&layout=edit&id=' . $profileId;

        if (!AlfaHelper::can(section: 'users', action: 'edit', user: $this->app->getIdentity())) {
            $app->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 'error');
            $this->setRedirect(Route::_($redirect, false));

            return;
        }

        $typed = $this->input->getString('credit_amount', '');
        $reason = trim($this->input->getString('credit_reason', ''));

        // What staff read when nothing was recorded. Every such answer leaves
        // through the one place below, escaped once.
        $refused = null;

        try {
            // The target account comes from the PROFILE ROW being edited,
            // never from the POST. A forged credit_user would let any
            // users.edit staffer credit an arbitrary wallet (their own
            // included) while the audit trail pointed at the victim's page.
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                'SELECT ' . $db->quoteName('id_user') . ' FROM ' . $db->quoteName('#__alfa_users')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $profileId,
            );
            $userId = (int) $db->loadResult();

            // The amount as typed, in the account's money; one finer than that
            // money holds is refused by name, never rounded. Its ABSOLUTE
            // value: the button says which way, and credit()/debit() apply
            // abs() themselves, so a negative POST would otherwise sail past
            // the fat-finger ceiling and mint an arbitrary amount.
            $wallet = Currency::getDefault();
            $amount = Money::zero($wallet);
            $unread = null;

            if ($typed !== '') {
                try {
                    $amount = ExactMoney::of(value: $typed, currency: $wallet, commaDecimal: true)->abs();
                } catch (ExactMoneyRefusal $e) {
                    $unread = $e->words(field: Text::_('COM_ALFA_USER_CREDIT_AMOUNT'));
                }
            }

            // This screen's own checks, each in its own sentence. An entry of
            // no amount and a manual entry with no reason are the ledger's
            // rules too; asked here first, staff read why nothing was
            // recorded, not the ledger's technical message.
            $refused = match (true) {
                $userId <= 0 => Text::_('COM_ALFA_USER_CREDIT_NO_LINKED_ACCOUNT'),
                $this->input->getInt('credit_user', $userId) !== $userId => Text::_('COM_ALFA_USER_CREDIT_TARGET_MISMATCH'),
                $unread !== null => $unread,
                $amount->greaterThan(Money::of(10000, $wallet)) => Text::_('COM_ALFA_USER_CREDIT_AMOUNT_TOO_LARGE'),
                $amount->isZero() => Text::_('COM_ALFA_USER_CREDIT_AMOUNT_REQUIRED'),
                $reason === '' => Text::_('COM_ALFA_USER_CREDIT_REASON_REQUIRED'),
                default => null,
            };

            if ($refused === null) {
                $entry = $direction === 'credit'
                    ? CustomerCreditLedger::credit(userId: $userId, amount: $amount, sourceType: CustomerCreditLedger::SOURCE_MANUAL, sourceId: null, note: $reason)
                    : CustomerCreditLedger::debit(userId: $userId, amount: $amount, sourceType: CustomerCreditLedger::SOURCE_MANUAL, sourceId: null, note: $reason);

                $app->enqueueMessage(
                    Text::sprintf(
                        'COM_ALFA_USER_CREDIT_APPLIED',
                        \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault()->format((float) $entry->amount, false),
                        \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault()->format((float) $entry->balance_after, false),
                    ),
                    'success',
                );
            }
        } catch (CreditNoteRefusedException $e) {
            // The ledger's own refusal, a debit of more than the account
            // holds: its plain reason.
            $refused = $e->reason();
        } catch (Throwable $e) {
            // Anything else is an entry that could not be written. Staff read
            // this screen's own sentence; the technical message goes to the
            // credit log, which outlives the one-shot message.
            try {
                CustomerCreditLedger::ensureLogger();
                Log::add(
                    entry:    sprintf('Customer profile %d: a manual %s of %s could not be recorded: %s', $profileId, $direction, $typed, $e->getMessage()),
                    priority: Log::WARNING,
                    category: 'com_alfa.credit',
                );
            } catch (Throwable) {
                // Swallowed: the sentence below still stands.
            }

            $refused = Text::_('COM_ALFA_USER_CREDIT_NOT_RECORDED');
        }

        if ($refused !== null) {
            $app->enqueueMessage(htmlspecialchars($refused, ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($redirect, false));
    }

    public function saveUserinfo(): void
    {
        $this->checkToken();
        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'users', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $userId = $this->input->post->getInt('user_id', 0);
        $sourceId = $this->input->post->getInt('source_id', 0);
        $errors = [];
        $data = $this->validateUserinfo($errors);

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        if ($errors) {
            echo new JsonResponse(null, implode(' ', $errors), true);
            $app->close();
        }

        if ($userId <= 0 || $data === []) {
            echo new JsonResponse(null, Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), true);
            $app->close();
        }

        $id = UserInfoHelper::insertData(
            userId: $userId,
            data: $data,
            existingId: $sourceId ?: null,
        );

        echo new JsonResponse(['id' => $id], Text::_('COM_ALFA_USERINFO_SAVED'));
        $app->close();
    }

    /**
     * Validate a posted saved-info row against the same form the screen renders.
     *
     * The backend used to write this straight from `$_POST`: no required rule,
     * no filter, no ShowOn. It now runs the identical authority as the
     * storefront: fields a rule hides are pruned (so a hidden field cannot be
     * demanded, nor smuggled in), and what remains is the FILTERED result.
     *
     * @param array $errors Filled with the validation messages, if any.
     *
     * @return array The filtered field values; empty when validation failed.
     *
     * @since  2.0.0
     */
    private function validateUserinfo(array &$errors): array
    {
        $posted = (array) ($this->input->post->get('uinfo', [], 'array'));

        // Validated against the form built for the row's OWNER (the identity
        // rules and per-user visibility are the customer's, not the admin's).
        $ownerId = $this->input->getInt('user_id', 0);
        $form = UserInfoHelper::adminForm(userId: $ownerId);

        $posted[FieldsHelper::FIELDS_KEY] = SubmitAuthority::prune(
            form: $form,
            fields: FieldsHelper::getFields('cart.form', null, $ownerId),
            values: (array) ($posted[FieldsHelper::FIELDS_KEY] ?? []),
            group: FieldsHelper::FIELDS_KEY,
        );

        if (!$form->validate($posted)) {
            foreach ($form->getErrors() as $error) {
                $errors[] = $error instanceof Throwable ? $error->getMessage() : (string) $error;
            }

            return [];
        }

        $filtered = $form->filter($posted);

        return (array) ($filtered[FieldsHelper::FIELDS_KEY] ?? []);
    }

    /**
     * Popup (modal iframe) save: one saved-info row through the same
     * contract as the frontend screen, then the return layout reloads
     * the parent.
     *
     *
     * @since  2.0.0
     */
    public function saveUserinfoForm(): void
    {
        $this->checkToken();
        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'users', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $userId = $this->input->post->getInt('user_id', 0);
        $sourceId = $this->input->post->getInt('source_id', 0);
        $errors = [];
        $data = $this->validateUserinfo($errors);

        $backUrl = 'index.php?option=com_alfa&view=user&layout=edit_userinfo&tmpl=component&user_id=' . $userId . '&info_id=' . $sourceId;

        if ($errors) {
            foreach ($errors as $error) {
                $app->enqueueMessage($error, 'error');
            }

            $this->setRedirect(Route::_($backUrl, false));

            return;
        }

        if ($userId <= 0 || $data === []) {
            $app->enqueueMessage(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');
            $this->setRedirect(Route::_($backUrl, false));

            return;
        }

        UserInfoHelper::insertData(
            userId: $userId,
            data: $data,
            existingId: $sourceId ?: null,
        );

        $app->enqueueMessage(Text::_('COM_ALFA_USERINFO_SAVED'));
        $this->setRedirect(Route::_('index.php?option=com_alfa&view=user&layout=edit_userinfo_return&tmpl=component', false));
    }

    /**
     * AJAX: delete a saved-info row, refused while any order references it.
     *
     *
     * @since  2.0.0
     */
    public function deleteUserinfo(): void
    {
        $this->checkToken();
        $app = Factory::getApplication();

        if (!AlfaHelper::can(section: 'users', action: 'edit', user: $app->getIdentity())) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }

        $userId = $this->input->post->getInt('user_id', 0);
        $id = $this->input->post->getInt('id', 0);

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        if ($userId <= 0 || $id <= 0 || UserInfoHelper::getRow(userId: $userId, id: $id) === null) {
            echo new JsonResponse(null, Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), true);
            $app->close();
        }

        if (UserInfoHelper::isUsedInOrders(id: $id)) {
            echo new JsonResponse(null, Text::_('COM_ALFA_USERINFO_DELETE_DENIED'), true);
            $app->close();
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_user_info'))
                ->where($db->quoteName('id') . ' = ' . $id),
        )->execute();

        echo new JsonResponse(['id' => $id], Text::_('COM_ALFA_USERINFO_DELETED'));
        $app->close();
    }
}
