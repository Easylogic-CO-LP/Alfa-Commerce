<?php
/**
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Layout: filter_form
 *
 * Renders the list filter/search form and supporting JS that strips
 * empty fields before submission so the URL stays clean.
 *
 * Expected $displayData keys:
 *   - view   : the current HtmlView instance (must expose $filterForm)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Uri\Uri;

$view       = $displayData['view'];
$filterForm = $view->filterForm ?? null;

if (empty($filterForm)) {
	return;
}
?>

    <form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>"
          method="get"
          name="adminForm"
          id="adminForm">

		<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $view]); ?>

    </form>

<?php
$wa = $view->document->getWebAssetManager();
$wa->addInlineScript(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('adminForm');

    if (!form) {
        return;
    }

    // Override both programmatic submit() calls and native form submissions
    // so empty fields are stripped from the URL in both cases.
    const nativeSubmit = HTMLFormElement.prototype.submit;

    const stripEmptyFields = function () {
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (field.name && field.value.trim() === '') {
                field.disabled = true;
            }
        });
    };

    form.submit = function () {
        stripEmptyFields();
        nativeSubmit.call(form);
    };

    form.addEventListener('submit', function () {
        stripEmptyFields();
    });
});
JS);