<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Toolbar;

\defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Toolbar\Toolbar;

/**
 * The panel toolbar a plugin talks to: a thin fluent adapter over Joomla's Toolbar.
 *
 * This is one of the few seams Alfa deliberately owns (the toolbar is a place Joomla has
 * changed between majors). A plugin adds buttons in terms Alfa guarantees:
 *
 *   $toolbar->addNew()->delete()->custom('rebuild', 'refresh', 'Rebuild index');
 *
 * and never calls ToolbarHelper or the Joomla Toolbar directly. Task verbs are prefixed to
 * the shared panel controller automatically ('add' -> 'panel.add'), so a plugin never
 * hardcodes the controller name either. If Joomla's toolbar API shifts, this one class
 * adapts and every plugin keeps working. For anything not wrapped here, {@see self::joomla()}
 * returns the raw Toolbar: an escape hatch, not the default path.
 *
 * @since  1.7.0
 */
final class PanelToolbar
{
    /** Shared panel controller task prefix; a bare verb routes to it. */
    private const PREFIX = 'panel.';

    /**
     * The framework-owned verbs a plugin's custom button may never claim: a custom task
     * that collided with one of these would silently shadow a native mutation.
     */
    private const NATIVE = ['display', 'add', 'edit', 'cancel', 'save', 'apply', 'delete'];

    /**
     * The native buttons the framework draws, in TOOLBAR language: the words a Joomla user
     * reads on the buttons, not the task verbs behind them ('new' is the New button even
     * though its task is panel.add).
     *
     * @since  1.8.0
     */
    public const NATIVE_NEW = 'new';
    public const NATIVE_DELETE = 'delete';
    public const NATIVE_SAVE = 'save';
    public const NATIVE_CANCEL = 'cancel';

    /**
     * @var array<string, bool> The native buttons this panel has vetoed, by button name.
     * @since  1.8.0
     */
    private array $suppressed = [];

    /**
     * @param Toolbar $toolbar The native toolbar this adapts.
     *
     * @since  1.7.0
     */
    public function __construct(private readonly Toolbar $toolbar)
    {
    }

    /**
     * Veto native buttons the framework would otherwise draw for this screen.
     *
     * The framework draws the native verbs by DEFAULT (New + Delete on a list, Save + Cancel
     * on an edit) so an ordinary CRUD panel needs no toolbar code at all; this is the escape
     * hatch for the panel whose domain is exceptional, a read-only registry, a log whose
     * rows are machine-created, an imported catalogue:
     *
     *   $event->getToolbar()->suppressNative();                          // read-only: no New, no Delete, no Save
     *   $event->getToolbar()->suppressNative(PanelToolbar::NATIVE_NEW);  // rows arrive by themselves; Delete stays
     *
     * Bare suppressNative() vetoes the MUTATING natives (New, Delete, Save) but never Cancel:
     * the edit screen hides the admin menu (hidemainmenu), so Cancel is the user's only exit;
     * hiding it too must be an explicit, named decision: suppressNative(PanelToolbar::NATIVE_CANCEL).
     *
     * THIS IS UX, NOT SECURITY. The controller still routes panel.add / panel.save /
     * panel.delete, CSRF-checked, whether or not a button points at them: a typed URL reaches
     * them with no button in sight. The owner's onAlfaPanelSave / onAlfaPanelDelete handlers
     * remain the real boundary; a read-only panel keeps answering them (message + redirect)
     * even after its buttons are gone.
     *
     * @param string ...$buttons Native button names (the NATIVE_* constants); none = all mutating.
     *
     *
     * @throws InvalidArgumentException On a name that is not a native button; caught before any
     *                                  veto is recorded, so a typo cannot half-configure the bar.
     *
     * @since  1.8.0
     */
    public function suppressNative(string ...$buttons): static
    {
        if ($buttons === []) {
            $buttons = [self::NATIVE_NEW, self::NATIVE_DELETE, self::NATIVE_SAVE];
        }

        $known = [self::NATIVE_NEW, self::NATIVE_DELETE, self::NATIVE_SAVE, self::NATIVE_CANCEL];

        foreach ($buttons as $button) {
            if (!\in_array($button, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    "Alfa panel toolbar: '%s' is not a native button; use 'new', 'delete', 'save' or 'cancel'"
                        . " (button names, not task verbs; the New button's task is 'add').",
                    $button,
                ));
            }
        }

        foreach ($buttons as $button) {
            $this->suppressed[$button] = true;
        }

        return $this;
    }

    /**
     * Whether a native button has been vetoed. The framework's view asks this after the
     * toolbar event has run and draws only the survivors.
     *
     * @param string $button A NATIVE_* button name.
     *
     *
     * @since  1.8.0
     */
    public function isSuppressed(string $button): bool
    {
        return $this->suppressed[$button] ?? false;
    }

    /**
     * A "New" button.
     *
     * @param string $verb The task verb (default 'add').
     *
     * @since  1.7.0
     */
    public function addNew(string $verb = 'add'): static
    {
        $this->toolbar->addNew($this->task($verb));

        return $this;
    }

    /**
     * An "Edit" button (acts on the checked row).
     *
     * @param string $verb The task verb (default 'edit').
     *
     * @since  1.7.0
     */
    public function edit(string $verb = 'edit'): static
    {
        $this->toolbar->editList($this->task($verb));

        return $this;
    }

    /**
     * A "Delete" button with a confirm prompt, acting on the checked rows.
     *
     * @param string $verb The task verb (default 'delete').
     * @param string $message Confirm message language key.
     *
     * @since  1.7.0
     */
    public function delete(string $verb = 'delete', string $message = 'JGLOBAL_CONFIRM_DELETE'): static
    {
        $this->toolbar->delete($this->task($verb))
            ->message($message)
            ->listCheck(true);

        return $this;
    }

    /**
     * A "Publish" button, acting on the checked rows.
     *
     * @param string $verb The task verb (default 'publish').
     *
     * @since  1.7.0
     */
    public function publish(string $verb = 'publish'): static
    {
        $this->toolbar->publish($this->task($verb))->listCheck(true);

        return $this;
    }

    /**
     * An "Unpublish" button, acting on the checked rows.
     *
     * @param string $verb The task verb (default 'unpublish').
     *
     * @since  1.7.0
     */
    public function unpublish(string $verb = 'unpublish'): static
    {
        $this->toolbar->unpublish($this->task($verb))->listCheck(true);

        return $this;
    }

    /**
     * A plugin's OWN button: any verb, routed to onAlfaPanelTask.
     *
     * @param string $verb The task verb (e.g. 'rebuild').
     * @param string $icon Icon name (without the icon- prefix).
     * @param string $text Button label (a language key or literal).
     * @param bool $listCheck Whether it requires a checked row.
     *
     * @since  1.7.0
     */
    public function custom(string $verb, string $icon, string $text, bool $listCheck = false): static
    {
        if (\in_array($verb, self::NATIVE, true)) {
            throw new InvalidArgumentException(
                "Alfa panel toolbar: '$verb' is a framework-owned task; a custom button needs its own verb.",
            );
        }

        $this->toolbar->standardButton($verb)
            ->task($this->task($verb))
            ->text($text)
            ->icon('icon-' . $icon)
            ->listCheck($listCheck);

        return $this;
    }

    /**
     * A plain link button (navigates, does not submit the list).
     *
     * @param string $text Label.
     * @param string $url Target URL.
     * @param string $icon Icon name (without the icon- prefix); '' for none.
     *
     * @since  1.7.0
     */
    public function link(string $text, string $url, string $icon = ''): static
    {
        $button = $this->toolbar->linkButton(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text)))
            ->text($text)
            ->url($url);

        if ($icon !== '') {
            $button->icon('icon-' . $icon);
        }

        return $this;
    }

    /**
     * The raw Joomla toolbar: for buttons this adapter does not wrap. An escape hatch;
     * prefer the methods above so the common surface stays insulated from Joomla churn.
     *
     * @since  1.7.0
     */
    public function joomla(): Toolbar
    {
        return $this->toolbar;
    }

    /**
     * Prefix a bare verb to the shared panel controller. A dotted task is rejected: the
     * toolbar routes only to the panel controller; {@see self::joomla()} is the way out.
     *
     * @param string $verb Bare task verb.
     *
     *
     * @throws InvalidArgumentException When the verb already carries a controller prefix.
     *
     * @since  1.7.0
     */
    private function task(string $verb): string
    {
        if (str_contains($verb, '.')) {
            throw new InvalidArgumentException(
                "Alfa panel toolbar: task verb '$verb' must be bare (no controller prefix); use joomla() for a custom control.",
            );
        }

        return self::PREFIX . $verb;
    }
}
