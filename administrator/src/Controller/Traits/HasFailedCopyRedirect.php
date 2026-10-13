<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller\Traits;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;

/**
 * Save as Copy refused on the copy's own data lands on a new-record form holding what was typed.
 *
 * Core sent such a copy back to the original's form, where its kept post (id 0) cannot refill the
 * original (KeptPost::forRecord()), so everything typed for the copy was lost. The new-record form
 * is the one that post refills, and Save there creates the copy. Only the landing changes: the
 * original stays as core leaves it, and a refused permission or check-in keeps core's redirect.
 *
 * @since  2.0.0
 */
trait HasFailedCopyRedirect
{
    /**
     * Whether this Save as Copy passed the original's check-in and the create permission.
     *
     * @since  2.0.0
     */
    private bool $copyReachedValidation = false;

    /**
     * Save; a copy refused at validation or by the model goes to the new-record form.
     *
     * @param string|null $key The name of the primary key.
     * @param string|null $urlVar The name of the URL variable for the id.
     *
     * @return bool True when saved.
     *
     * @since  2.0.0
     */
    public function save($key = null, $urlVar = null)
    {
        $copy = $this->getTask() === 'save2copy';
        $this->copyReachedValidation = false;

        $saved = parent::save(key: $key, urlVar: $urlVar);

        if (!$copy || $saved || !$this->copyReachedValidation) {
            return $saved;
        }

        // No record id: the new-record form, which the id-0 kept post refills.
        $this->setRedirect(url: $this->getRedirectUrlToItem(recordId: null));

        return false;
    }

    /**
     * Core calls this after the check-in and the permission check, and before it validates.
     *
     * @param Form $form The form.
     * @param array $data The request data.
     *
     *
     * @since  2.0.0
     */
    protected function normalizeRequestData(Form $form, array $data): array
    {
        $this->copyReachedValidation = $this->getTask() === 'save2copy';

        return parent::normalizeRequestData(form: $form, data: $data);
    }
}
