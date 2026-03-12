<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;

$user   = Factory::getApplication()->getIdentity();
$userId = $user->get('id');

// Import CSS
$wa = $this->document->getWebAssetManager()
    ->useStyle('com_alfa.item');

$manufacturers = $this->item->manufacturers;
?>

<?php if (!empty($manufacturers)): ?>
    <div class="item-groups">
        <h2><?php echo Text::_('Manufacturers'); ?></h2>
        <div class="item-group-inner">
            <?php foreach ($manufacturers as $id => $manufacturerData) : ?>
                <div class="group-item">
                    <a href="<?php echo Route::_('index.php?option=com_alfa&view=manufacturer&id=' . (int) $id); ?>">
                        <div class="group-name">
                            <?php echo $manufacturerData['name']; ?>
                        </div>
                        <?php if (!empty($manufacturerData['media'])) : ?>
                            <?php $media = reset($manufacturerData['media']); ?>
                            <div class="group-media">
                                <img src="<?php echo $media->path; ?>" alt="<?php echo !empty($media->alt) ? $media->alt : $manufacturerData['name'] . ' logo'; ?>">
                            </div>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>







