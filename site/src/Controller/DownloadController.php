<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Download controller: serves purchased downloadable files.
 *
 * URL contract: `index.php?option=com_alfa&task=download.serve&token=<raw>`
 * plus `&sk=<secure_key>` for guest orders. The token is the ONLY resource
 * identifier; no order/item/file ids ever appear in the URL.
 *
 * Gate sequence (every hit, in order):
 *   1. token resolves (sha256 match against token_hash)
 *   2. entitlement status is servable (Entitlements::isServable)
 *   3. LIVE order gate: the order's CURRENT status is still in the
 *      is_completed family: a refund kills mailed links instantly,
 *      independently of entitlement status
 *   4. time window (expires_at): lazily flips the row to EXPIRED
 *   5. ownership: customer orders require the matching logged-in user;
 *      guest orders require the order's secure_key
 *   6. object exists in storage (current file of id_download when still
 *      present/published, else the frozen snapshot)
 *   7. atomic claim (status + count guard in one UPDATE): two simultaneous
 *      clicks can never overshoot; the increment is final by design
 * Only then do bytes flow, chunked, with the customer-facing filename.
 *
 * @since  1.3.0
 */
class DownloadController extends BaseController
{
    /**
     * Serve a purchased file for a raw download token.
     *
     * @return void Streams the file and closes the application, or redirects
     *              with a message on any refused gate.
     *
     * @since  1.3.0
     */
    public function serve()
    {
        $app = $this->app;
        $input = $app->getInput();

        $token = (string) $input->get('token', '', 'alnum');
        $secureKey = (string) $input->get('sk', '', 'alnum');

        $entitlement = Entitlements::findByToken(rawToken: $token);

        if ($entitlement === null) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_NOT_FOUND'));
            return;
        }

        if (!Entitlements::isServable(status: (int) $entitlement->status)) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_NOT_AVAILABLE'));
            return;
        }

        // Live order gate: the current status must still grant downloads:
        // the same question the grant asked. Testing the completed family here
        // instead would refuse every link a non-completed granting status
        // legitimately issued.
        $order = $this->loadOrder(orderId: (int) $entitlement->id_order);

        if ($order === null || !Entitlements::statusEntitles((int) $order->id_order_status)) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_ORDER_NOT_COMPLETED'));
            return;
        }

        // Time window: evaluated lazily on hit (no cron).
        if (!empty($entitlement->expires_at)
            && Factory::getDate('now', 'UTC')->toSql() > $entitlement->expires_at) {
            Entitlements::markExpired(entitlementId: (int) $entitlement->id);
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_EXPIRED'));
            return;
        }

        // Ownership: registered orders need the matching user; guest orders
        // need the order's secure_key from the email link. An order whose
        // account no longer exists is judged as a guest order rather than
        // redirecting to a login nobody can complete; its links carry no key,
        // so the answer is a plain refusal instead of a loop.
        $userId = (int) $app->getIdentity()->id;
        $orderUserId = (int) ($order->id_user ?? 0);

        if ($orderUserId > 0) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $accountExists = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('id') . ' = ' . $orderUserId),
            )->loadResult() > 0;

            if (!$accountExists) {
                $orderUserId = 0;
            }
        }

        if ($orderUserId > 0) {
            if ($userId === 0) {
                $app->enqueueMessage(Text::_('COM_ALFA_DOWNLOAD_ERROR_LOGIN_REQUIRED'), 'notice');
                $app->redirect(Route::_(
                    'index.php?option=com_users&view=login&return='
                    . base64_encode('index.php?option=com_alfa&task=download.serve&token=' . $token),
                    false,
                ));
                return;
            }

            if ($userId !== $orderUserId) {
                $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_NOT_YOURS'));
                return;
            }
        } elseif ($secureKey === '' || !hash_equals((string) $order->secure_key, $secureKey)) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_NOT_YOURS'));
            return;
        }

        // Resolve the object: follow the CURRENT file while the catalog row
        // still exists and is published (fixes propagate to buyers); fall
        // back to the frozen snapshot only when the catalogue row is GONE.
        //
        // AN UNPUBLISHED FILE IS NOT SERVED. It used to be: the lookup filtered
        // on state = 1, found nothing, and fell through to the frozen snapshot
        // - so unpublishing a file to stop distributing it silently kept
        // serving the old bytes to every existing buyer. Publication is now
        // deterministic on both sides: syncForLine() revokes the entitlement,
        // and this refuses in the window before a reconcile has run.
        $file = $this->resolveFile(entitlement: $entitlement);

        if ($file === null) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_FILE_UNAVAILABLE'));

            return;
        }

        if (!Storage::exists(disk: $file->storage_disk, key: $file->storage_key)) {
            Log::add(
                entry:    'Download object missing for entitlement ' . $entitlement->id
                          . ' (key ' . substr($file->storage_key, 0, 8) . '...)',
                priority: Log::ERROR,
                category: 'com_alfa.downloads',
            );
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_FILE_UNAVAILABLE'));
            return;
        }

        // Atomic claim: the last gate. Refusal here means the limit was
        // reached (possibly by a concurrent request holding the same link).
        $claimed = Entitlements::claimDownload(
            entitlementId: (int) $entitlement->id,
            ip:            (string) $input->server->getString('REMOTE_ADDR', ''),
            userAgent:     (string) $input->server->getString('HTTP_USER_AGENT', ''),
        );

        if (!$claimed) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_LIMIT_REACHED'));
            return;
        }

        // Delivery is entirely Storage's job (headers, transport,
        // termination); serve() only returns on failure, i.e. the object
        // vanished between the exists() gate and the open.
        if (!Storage::serve(file: $file)) {
            $this->deny(message: Text::_('COM_ALFA_DOWNLOAD_ERROR_FILE_UNAVAILABLE'));
        }
    }

    /**
     * The object/metadata to serve: the entitlement's current catalog file
     * when its row is still present and published, else the frozen snapshot.
     *
     * @param object $entitlement `#__alfa_order_downloads` row.
     *
     * @return object {storage_disk, storage_key, mime_type, display_filename}
     *
     * @since  1.3.0
     */
    protected function resolveFile(object $entitlement): ?object
    {
        if (!empty($entitlement->id_download)) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $downloadId = (int) $entitlement->id_download;

            // NO state FILTER: the state is READ, so an unpublished row can be
            // told apart from a deleted one. Filtering made the two
            // indistinguishable and both fell through to the snapshot.
            $query = $db->getQuery(true)
                ->select($db->quoteName(['storage_disk', 'storage_key', 'mime_type', 'display_filename', 'state']))
                ->from($db->quoteName('#__alfa_item_downloads'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $downloadId);

            $db->setQuery(query: $query);
            $current = $db->loadObject();

            if ($current !== null) {
                // PUBLISHED IS A CONDITION OF SERVING. The merchant withdrew
                // the file; the buyer's history and counter stay, and future
                // access stops.
                if ((int) $current->state !== 1) {
                    return null;
                }

                if (Storage::exists(disk: $current->storage_disk, key: $current->storage_key)) {
                    return $current;
                }
            }
        }

        // The catalogue row is GONE. The frozen snapshot is the only truth
        // left and is what the buyer bought: the deliberate soft-FK design,
        // and a different case from a file the merchant unpublished.

        return (object) [
            'storage_disk' => Storage::DISK_LOCAL,
            'storage_key' => (string) $entitlement->storage_key,
            'mime_type' => (string) $entitlement->mime_type,
            'display_filename' => (string) $entitlement->display_filename,
        ];
    }

    /**
     * Minimal order row for the gates (status, owner, guest key).
     *
     * @param int $orderId Order PK.
     *
     * @since  1.3.0
     */
    protected function loadOrder(int $orderId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_user', 'id_order_status', 'secure_key']))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $orderId);

        $db->setQuery(query: $query);

        return $db->loadObject() ?: null;
    }

    /**
     * Refuse the request: message + redirect to the site root.
     *
     * Wrong-but-wellformed tokens and exhausted/expired links land here;
     * the message states the category, never internals.
     *
     * The destination is the site root's own address, never a routed
     * 'index.php': the site router fills a URL without an option or Itemid
     * from the current request, which rebuilt this very download link, so
     * every refusal redirected to itself and the buyer never saw why.
     *
     * @param string $message Translated, customer-facing reason.
     *
     * @since  1.3.0
     */
    protected function deny(string $message): void
    {
        $this->app->enqueueMessage($message, 'warning');
        $this->app->redirect(Uri::root());
    }
}
