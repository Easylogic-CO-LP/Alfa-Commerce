<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\AdoptionMemory;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartIdentity;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartLocator;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Cart\GuestCartAdoption;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

defined('_JEXEC') or die;

/**
 * Open a cart for one request.
 *
 * Nothing else: resolve which cart this is (discovery and guest adoption are
 * the locator's) with the adoption failure net; compose the owners that
 * serve it; hold the lines adoption moved to this cart's rules; and read the
 * cart. It computes no total, writes no line and speaks to
 * no shopper of its own accord.
 *
 * @since  1.10.0
 */
final class CartOpener
{
    /**
     * @param CartRepository|null $repository The store, or one the caller supplies.
     *
     * @since  1.10.0
     */
    public function __construct(private readonly ?CartRepository $repository = null)
    {
    }

    /**
     * @param int $cartId A named cart, or 0 to discover one.
     * @param CartSubject|null $subject Whose cart; the visitor's own when omitted.
     * @param CartResolution|null $mode What the operation may do; Mutable when omitted.
     *
     * @since  1.10.0
     */
    public function open(int $cartId = 0, ?CartSubject $subject = null, ?CartResolution $mode = null): OpenedCart
    {
        $app = Factory::getApplication();
        $db = Factory::getContainer()->get('DatabaseDriver');
        $repository = $this->repository ?? new CartRepository($db);
        $keys = new RecognizeKey($app->input);
        $subject ??= CartSubject::ambient();
        $recognizeKey = $keys->read();
        $namedCartId = $cartId;

        try {
            $identity = (new CartLocator(
                new GuestCartAdoption($repository, new AdoptionMemory($app)),
            ))->resolve($subject, $mode ?? CartResolution::Mutable, $namedCartId, (string) $recognizeKey);
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add('Guest cart adoption failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');

            $identity = CartIdentity::of($namedCartId, (string) $recognizeKey, $mode ?? CartResolution::Mutable, $namedCartId);
        }

        $messaging = new CartMessaging($app);
        $configuration = new CartLineConfiguration($repository);
        $hydrator = new CartHydrator(
            $repository,
            new CartItemLoader($db),
            $configuration,
            new CartLinePresenter($app, $subject->customer()),
            $messaging,
            $app->bootComponent('com_alfa')->getMVCFactory(),
        );
        $plugins = new CartPluginDispatch($app);
        $rows = null;
        $computation = new CartComputation(
            $repository,
            $plugins,
            static fn (): HydratedCart => $hydrator->current(),
            static function () use (&$rows): void {
                $rows->touch();
            },
        );
        $rows = new CartRowWriter($repository, $hydrator, $computation, $messaging, $keys);
        $lines = new CartLineWriter(
            $repository,
            $hydrator,
            new CartItemLoader($db),
            $configuration,
            new LineQuantityClamp($repository, $messaging),
            $rows,
            $messaging,
        );

        // The lines adoption moved are held to this cart's rules BEFORE the
        // cart is read, so what is read is what the rules left.
        $hydrator->begin($identity, $subject);

        if ($identity->adoption->needsReclamp()) {
            $lines->reclamp($identity->adoption->movedLines);
        }

        $hydrator->reload();

        return new OpenedCart(
            $hydrator,
            $computation,
            $rows,
            $lines,
            $configuration,
            $plugins,
            new MethodReconciliation($repository, $computation, static fn (): HydratedCart => $hydrator->current(), $rows),
            $messaging,
        );
    }
}
