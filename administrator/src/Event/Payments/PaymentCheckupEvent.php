<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentEnvironment;
use BadMethodCallException;
use InvalidArgumentException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The checkup of ONE payment method: the setup steps its gateway needs, each
 * with where it stands.
 *
 * A gateway answers onPaymentCheckup(PaymentCheckupEvent $event) by adding
 * its steps in order. Most steps are judged from what is already known (the
 * saved settings, the plugin's own log) and cost nothing to draw. Steps that
 * need the provider (are these credentials accepted, is the webhook
 * registered) run only when isCheck() is true, which is the operator
 * pressing "Check connection"; the plugin then logs what it found, so the
 * next plain draw can show the result and when it was taken.
 *
 * Nothing a checkup does may move money or change the method: it reads.
 *
 * Arguments: 'subject' - the method row (id, type, state, params as a JSON
 * string or array); 'variant' - 'sandbox' or 'live' for a gateway card, which
 * judges that credential set only, 'general' for a method without sets
 * (manual payments); 'check' - bool.
 *
 * The variants 'sandbox' and 'live' are, by definition, the same strings as
 * PaymentEnvironment::SANDBOX and ::LIVE: a card is the card of one set.
 *
 * @since  2.0.0
 */
final class PaymentCheckupEvent extends PaymentsEvent
{
    /** The step is complete. @since 2.0.0 */
    public const DONE = 'done';

    /** The step still has to be taken. @since 2.0.0 */
    public const TODO = 'todo';

    /** The step was tried and is wrong. @since 2.0.0 */
    public const FAILED = 'failed';

    /** Only the operator can confirm it; the help says how. @since 2.0.0 */
    public const MANUAL = 'manual';

    /**
     * The provider or this method cannot do it, or does not offer it yet; the
     * help says which. Not counted in the progress.
     *
     * @since  2.0.0
     */
    public const UNAVAILABLE = 'unavailable';

    /** Which list is asked for: a gateway's test or live mode, or a method without modes. @since 2.0.0 */
    public const VARIANTS = ['sandbox', 'live', 'general'];

    /**
     * @param string $name The event name.
     * @param array $arguments 'subject', 'variant' and optionally 'check'.
     *
     * @throws BadMethodCallException When the variant is not sandbox, live or general.
     *
     * @since  2.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\in_array($this->arguments['variant'] ?? '', self::VARIANTS, true)) {
            throw new BadMethodCallException("Event {$name} needs 'variant' = sandbox, live or general");
        }

        $this->arguments['steps'] = [];
        $this->arguments['offered'] = false;
    }

    /**
     * @return int The payment method's id.
     *
     * @since  2.0.0
     */
    public function getMethodId(): int
    {
        return (int) ($this->getSubject()->id ?? 0);
    }

    /**
     * The method's SAVED settings: the checkup judges what is stored, never a
     * half-edited form.
     *
     *
     * @since  2.0.0
     */
    public function getParams(): Registry
    {
        return new Registry($this->getSubject()->params ?? '');
    }

    /**
     * @return string 'sandbox', 'live' or 'general'.
     *
     * @since  2.0.0
     */
    public function getVariant(): string
    {
        return $this->arguments['variant'];
    }

    /**
     * Whether this card's set is the one new payments use, as SAVED. Always
     * false for a card without a set ('general').
     *
     *
     * @since  2.0.0
     */
    public function isInUse(): bool
    {
        return $this->getVariant() !== 'general'
            && PaymentEnvironment::active(params: $this->getParams()->toArray()) === $this->getVariant();
    }

    /**
     * @return bool True when the operator asked for the provider to be contacted.
     *
     * @since  2.0.0
     */
    public function isCheck(): bool
    {
        return (bool) ($this->arguments['check'] ?? false);
    }

    /**
     * Declare that this gateway has checks worth a "Check connection" button.
     *
     *
     * @since  2.0.0
     */
    public function offerCheck(): static
    {
        $this->arguments['offered'] = true;

        return $this;
    }

    /**
     * @return bool Whether the button is shown.
     *
     * @since  2.0.0
     */
    public function isCheckOffered(): bool
    {
        return $this->arguments['offered'];
    }

    /**
     * Add the next step.
     *
     * @param string $title Translated step title.
     * @param string $status One of the class constants.
     * @param string $help Translated guidance; may carry markup from the plugin's
     *                     own language file, and the tokens {webhook} and {return},
     *                     which are replaced by this site's addresses.
     * @param string $detail Plain text: what was found and when (escaped on output).
     *
     *
     * @since  2.0.0
     */
    public function addStep(string $title, string $status, string $help = '', string $detail = ''): static
    {
        if (!\in_array($status, [self::DONE, self::TODO, self::FAILED, self::MANUAL, self::UNAVAILABLE], true)) {
            $status = self::TODO;
        }

        $this->arguments['steps'][] = ['title' => $title, 'status' => $status, 'help' => $help, 'detail' => $detail];

        return $this;
    }

    /**
     * @return array<int, array{title: string, status: string, help: string, detail: string}>
     *
     * @since  2.0.0
     */
    public function getSteps(): array
    {
        return $this->arguments['steps'];
    }

    /**
     * Say when the provider was last asked, and what the answer was in one
     * line, shown beside the button. The plugin reads it from its own log.
     *
     * @param string $text Plain text, e.g. "Last checked 24 Sep 13:42, all accepted".
     *
     *
     * @since  2.0.0
     */
    public function setLastChecked(string $text): static
    {
        $this->arguments['checked'] = $text;

        return $this;
    }

    /**
     * @return string The last-check line, or ''.
     *
     * @since  2.0.0
     */
    public function getLastChecked(): string
    {
        return (string) ($this->arguments['checked'] ?? '');
    }

    /**
     * The address a gateway posts notifications to, for this method's plugin.
     * A sandbox or live card prints the address of its own set, which names
     * that set; a card without a set prints the plain address.
     *
     *
     * @since  2.0.0
     */
    public function webhookUrl(): string
    {
        $variant = $this->getVariant();
        $named = \in_array($variant, [PaymentEnvironment::SANDBOX, PaymentEnvironment::LIVE], true);

        return self::siteUrl(element: (string) ($this->getSubject()->type ?? ''), task: 'webhook', environment: $named ? $variant : null);
    }

    /**
     * The address a buyer returns to from the gateway, for this method's plugin.
     *
     *
     * @since  2.0.0
     */
    public function returnUrl(): string
    {
        return self::siteUrl(element: (string) ($this->getSubject()->type ?? ''), task: 'response');
    }

    /**
     * A public payment address of this site, in the form a gateway can reach.
     *
     * On a multilingual SEF site the plain index.php form is 301-redirected into
     * a route the SEF router then 404s, so every notification would be lost. The
     * component route with the default language's prefix, without a trailing
     * slash, is the form that reaches the controller. TLS is forced: gateways
     * post money notifications to https only.
     *
     * A notification address names the credential set it belongs to
     * (PaymentEnvironment::ADDRESS_PARAM), so its handler knows which set's
     * secret to check it with.
     *
     * @param string $element The payment plugin element.
     * @param string $task 'webhook' or 'response'.
     * @param string|null $environment PaymentEnvironment::SANDBOX or ::LIVE; null for an address without a set.
     *
     * @return string Absolute URL.
     *
     * @throws InvalidArgumentException On an environment other than sandbox or live.
     *
     * @since  2.0.0
     */
    public static function siteUrl(string $element, string $task, ?string $environment = null): string
    {
        $app = Factory::getApplication();
        $element = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($element));
        $query = 'task=payment.' . $task . '&method=' . $element;
        $root = rtrim(Uri::root(), '/') . '/';

        if ($environment !== null) {
            $query .= '&' . PaymentEnvironment::ADDRESS_PARAM . '=' . PaymentEnvironment::assert(environment: $environment);
        }

        if (!(bool) $app->get('sef', true)) {
            $url = $root . 'index.php?option=com_alfa&' . $query;
        } else {
            $url = $root . ((bool) $app->get('sef_rewrite', false) ? '' : 'index.php/')
                . self::languagePrefix() . 'component/alfa?' . $query;
        }

        return (string) preg_replace('#^http://#i', 'https://', $url);
    }

    /**
     * The default site language's URL prefix, when the language filter adds one.
     *
     * @return string 'en/' or ''.
     *
     * @since  2.0.0
     */
    private static function languagePrefix(): string
    {
        if (!PluginHelper::isEnabled('system', 'languagefilter')) {
            return '';
        }

        $filter = PluginHelper::getPlugin('system', 'languagefilter');
        $params = new Registry(\is_object($filter) ? ($filter->params ?? '') : '');

        if ((int) $params->get('remove_default_prefix', 0) === 1) {
            return '';
        }

        $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $languages = LanguageHelper::getLanguages('lang_code');
        $sef = (string) ($languages[$tag]->sef ?? '');

        return $sef !== '' ? $sef . '/' : '';
    }
}
