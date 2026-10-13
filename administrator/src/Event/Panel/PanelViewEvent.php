<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Panel;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Panel\PanelIdentity;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\Input\Input;

/**
 * A panel describes what to render for a screen: fired at the owning plugin (onAlfaPanelView)
 * from the shared Panel view. Two levels, native first:
 *
 *   NATIVE (the default path). The plugin names a Joomla form; the framework loads it (with
 *   com_alfa's own field types, including the multilingual ones, registered), binds the
 *   record, draws the Save / Save & Close / Cancel toolbar and renders the fields in its
 *   standard edit template. The plugin writes no markup:
 *
 *       public function onAlfaPanelView(PanelViewEvent $event): void
 *       {
 *           if ($event->getScreen() === 'edit') {
 *               $event->useForm('synonym');            // forms/synonym.xml, in the plugin
 *           }
 *       }
 *
 *   OWN MARKUP (the escape hatch). The plugin points at one of its OWN layout files, which
 *   the framework renders in place of the default template, receiving the same loaded
 *   $form (so it can call $form->renderField('title')) plus any layoutData. Use this only
 *   when a screen needs an arrangement the default template cannot express:
 *
 *       $event->useForm('synonym')->setLayout('synonyms.edit');   // your tmpl renders $form
 *
 * The layout name resolves against the plugin's OWN layouts directory (the view supplies
 * that base from the identity). The view merges the model's data (list items + pagination,
 * or the loaded record) with the plugin's layoutData, so the plugin only adds what the model
 * does not already provide.
 *
 * @since  2.0.0
 */
class PanelViewEvent extends AbstractEvent
{
    /**
     * Which screen is rendering: an OPEN string the plugin interprets. 'list' and 'edit'
     * are conventions; a plugin may add its own (e.g. 'import') and set no layout for the
     * screens it does not own, which the view renders as an empty body.
     *
     *
     * @since  2.0.0
     */
    public function getScreen(): string
    {
        return $this->arguments['screen'] ?? 'list';
    }

    /**
     * The request input, so a custom screen can read its own params.
     *
     *
     * @since  2.0.0
     */
    public function getInput(): Input
    {
        return $this->arguments['input'];
    }

    /**
     * Data the shared model already prepared (e.g. items + pagination for a list, or the
     * loaded record for an edit), merged under the plugin's layoutData at render.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    public function getData(): array
    {
        return $this->arguments['data'] ?? [];
    }

    /**
     * The form the plugin chose the framework to load and render for this screen: a file
     * basename in the plugin's own forms/ directory, without extension. Empty when the
     * screen is not a native form screen.
     *
     *
     * @since  2.0.0
     */
    public function getFormName(): string
    {
        return $this->arguments['form'] ?? '';
    }

    /**
     * Render this screen from a Joomla form the framework loads (forms/<name>.xml in the
     * plugin), binding the record and drawing the native toolbar. The default template
     * renders it; pair with {@see self::setLayout()} only to render it in your own tmpl.
     *
     * @param string $name Form file basename (in the plugin's forms/ dir, no extension).
     *
     *
     * @since  2.0.0
     */
    public function useForm(string $name): static
    {
        $this->arguments['form'] = $name;

        return $this;
    }

    /**
     * The layout the plugin chose (a file in its own layouts dir, without extension).
     *
     *
     * @since  2.0.0
     */
    public function getLayout(): string
    {
        return $this->arguments['layout'] ?? '';
    }

    /**
     * Choose the layout to render for this screen.
     *
     * @param string $layout Layout name (in the plugin's own layouts dir, no extension).
     *
     *
     * @since  2.0.0
     */
    public function setLayout(string $layout): static
    {
        $this->arguments['layout'] = $layout;

        return $this;
    }

    /**
     * The plugin's own data for the layout.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    public function getLayoutData(): array
    {
        return $this->arguments['layoutData'] ?? [];
    }

    /**
     * Pass data to the chosen layout (merged over the model data).
     *
     * @param array<string, mixed> $data Layout data.
     *
     *
     * @since  2.0.0
     */
    public function setLayoutData(array $data): static
    {
        $this->arguments['layoutData'] = $data;

        return $this;
    }

    /**
     * Who this request is for.
     *
     *
     * @since  2.0.0
     */
    public function getIdentity(): PanelIdentity
    {
        return $this->arguments['identity'];
    }
}
