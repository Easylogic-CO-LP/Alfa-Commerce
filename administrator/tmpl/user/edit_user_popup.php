<?php
/**
 * Sub-template: linked Joomla account display, edit modal and trigger buttons.
 *
 * Rendered from edit.php via $this->loadTemplate('user_popup').
 * Expects $this->linkedUser (bool) and $this->editUrl (string).
 *
 * @version    1.0.1
 * @package    Com_Alfa
 */

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

if (!$this->linkedUser || !$this->editUrl) {
    return;
}
?>

<?php // ── Name + email ───────────────────────────────────────────────────── ?>
<p class="mb-1">
    <strong><?php echo $this->escape($this->item->joomla_name ?? '—'); ?></strong>
    <span class="text-muted ms-2">
        <?php echo $this->escape($this->item->joomla_email ?? ''); ?>
    </span>
</p>

<?php // ── Trigger buttons ────────────────────────────────────────────────── ?>
<button type="button"
        class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal"
        data-bs-target="#joomlaUserModal">
    <span class="icon-expand me-1" aria-hidden="true"></span>
    <?php echo Text::_('COM_ALFA_BTN_EDIT_JOOMLA_USER_POPUP'); ?>
</button>

<?php
// New-tab link gets a return URL so Joomla redirects back to this record
// after save, instead of dropping the user on the com_users list.
$newTabUrl = $this->editUrl
    . '&return=' . base64_encode(
        'index.php?option=com_alfa&task=user.edit&id=' . (int) $this->item->id
    );
?>

<?php // ── Modal ──────────────────────────────────────────────────────────── ?>
<?php
echo HTMLHelper::_(
    'bootstrap.renderModal',
    'joomlaUserModal',
    [
        'title'  => Text::_('COM_ALFA_MODAL_EDIT_USER_TITLE'),
        'height' => '90vh',
        'width'  => '100%',
        'footer' => '<button type="button" class="btn btn-secondary"
                             data-bs-dismiss="modal">'
                  . Text::_('JCLOSE')
                  . '</button>',
    ],
    '<div style="position:relative;width:100%;height:80vh;">
        <div id="joomlaUserLoader"
             style="position:absolute;inset:0;display:flex;align-items:center;
                    justify-content:center;background:#fff;z-index:10;">
            <div class="spinner-border text-secondary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
        <iframe
            id="joomlaUserIframe"
            src=""
            data-src="' . $this->escape($this->editUrl) . '"
            style="width:100%;height:100%;border:0;opacity:0;transition:opacity .15s ease;"
            allowfullscreen>
        </iframe>
    </div>'
);
?>

<?php // ── JS ─────────────────────────────────────────────────────────────── ?>
<script>
document.addEventListener('DOMContentLoaded', () => {

    const modal  = document.getElementById('joomlaUserModal');
    const iframe = document.getElementById('joomlaUserIframe');
    const loader = document.getElementById('joomlaUserLoader');

    if (!modal || !iframe || !loader) return;

    let editFormLoaded = false;

    function showLoader() {
        loader.style.display = 'flex';
        iframe.style.opacity = '0';
    }

    function hideLoader() {
        loader.style.display = 'none';
        iframe.style.opacity = '1';
    }

    modal.addEventListener('show.bs.modal', () => {
        editFormLoaded = false;
        showLoader();
        iframe.src = iframe.dataset.src;
    });

    iframe.addEventListener('load', () => {
        if (!editFormLoaded) {
            editFormLoaded = true;
            hideLoader();
            return;
        }
        bootstrap.Modal.getInstance(modal).hide();
        window.location.reload();
    });

    modal.addEventListener('hide.bs.modal', () => {
        showLoader();
        iframe.src = '';
        editFormLoaded = false;
    });

});
</script>