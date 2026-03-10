<?php
/**
 * @package     Com_Alfa
 * @subpackage  Layouts
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @version     2.1.0 (UTF-8 FIXED)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;
/**
 * Layout variables
 * -----------------
 * @var   object $displayData Data passed from the layout caller
 * @var   string $displayData ->url            The current page URL
 * @var   string $displayData ->title          The page title
 * @var   string $displayData ->metaTitle      The meta title (optional)
 * @var   string $displayData ->metaDesc       The meta description
 * @var   string $displayData ->alias          The URL alias
 * @var   string $displayData ->content        The content (optional)
 * @var   string $displayData ->focusKeyword   The focus keyword (optional)
 * @var   string $displayData ->siteName       Site name
 * @var   array  $displayData ->analysis       Array of SEO analysis results
 * @var   int    $displayData ->score          Overall SEO score (0-100)
 */

$app = Factory::getApplication();
$wa = $app->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_alfa');

// Get data with defaults
$url          = $displayData->url ?? '';
$realUrl      = $displayData->realUrl ?? '';
$title        = $displayData->title ?? '';
$metaTitle    = $displayData->metaTitle ?? $title;
$metaDesc     = $displayData->metaDesc ?? '';
$content      = $displayData->content ?? '';
$additionalContent = $displayData->additionalContent ?? [];
$alias        = $displayData->alias ?? '';
$defaultAlias = $displayData->defaultAlias ?? '';
$siteName     = $displayData->siteName ?? 'Your Site';
$analysis     = $displayData->analysis ?? [];
$score        = $displayData->score ?? 0;
$focusKeyword = $displayData->focusKeyword ?? '';
$itemType     = $displayData->itemType ?? 'category';
$itemId       = $displayData->itemId ?? 0;
$robots       = $displayData->robots ?? '';

// Get field selectors from displayData
$fieldSelectors = $displayData->fieldJsSelectors ?? [
	'title' => '#jform_name',
	'metaTitle' => '#jform_meta_title',
	'metaDesc' => '#jform_meta_desc',
	'alias' => '#jform_alias',
	'content' => '#jform_desc',
	'additionalContent' => [],
	'focusKeyword' => '[data-seo-focus-keyword-field]',
    'robots' => '#jform_robots'
];

// Pass configuration to JavaScript
$app->getDocument()->addScriptOptions('seo-preview', [
	'debug' => false,
	'itemType' => $itemType,
	'itemId' => $itemId,
	'fields' => $fieldSelectors,
	'defaultAlias' => $defaultAlias,
	'debounceDelay' => 500,
	'endpoint' => 'index.php?option=com_alfa&task=seo.getPreview&format=json'
]);

$wa->usePreset('com_alfa.seo-preview');

// Parse URL
$urlParts = parse_url($url);
$domain   = $urlParts['host'] ?? 'example.com';
$domain   = str_replace('www.', '', $domain);
$path     = $urlParts['path'] ?? '/';

// Prepare title display (use metaTitle if set, otherwise title - matches frontend behavior)
// Step 1: Clean the inputs
$metaTitleCleaned = trim($metaTitle);
$titleCleaned     = trim($title);

$titleDisplay = $metaTitleCleaned;

// Step 4: Truncate if too long
if (mb_strlen($titleDisplay, 'UTF-8') > 60)
{
	$titleDisplay = mb_substr($titleDisplay, 0, 57, 'UTF-8') . '...';
}

// Prepare description display
$descDisplay = trim($metaDesc) !== '' ?
	$metaDesc :
	\Alfa\Component\Alfa\Site\Helper\AlfaHelper::cleanContent(
		html: $content,
		removeTags: true,
		removeScripts: true,
		removeIsolatedPunctuation: false
	);

if (mb_strlen($descDisplay, 'UTF-8') > 160)
{
	$descDisplay = mb_substr($descDisplay, 0, 157, 'UTF-8') . '...';
}
if (empty($descDisplay))
{
	$descDisplay = Text::_('COM_ALFA_SEO_NO_DESCRIPTION');
}

// Score color
$scoreColor = '#dc3545'; // Red
if ($score >= 80)
{
	$scoreColor = '#28a745'; // Green
}
elseif ($score >= 50)
{
	$scoreColor = '#ffc107'; // Yellow/Orange
}

// Score text
$scoreText = Text::_('COM_ALFA_SEO_SCORE_CRITICAL');
if ($score >= 80)
{
	$scoreText = Text::_('COM_ALFA_SEO_SCORE_EXCELLENT');
}
elseif ($score >= 50)
{
	$scoreText = Text::_('COM_ALFA_SEO_SCORE_GOOD');
}

// Current date for preview
$currentDate = date('M d, Y');

// Group checks by status for better organization
$groupedChecks = [
	'error'   => [],
	'warning' => [],
	'good'    => [],
	'info'    => []
];

foreach ($analysis as $check)
{
	$status = $check['status'] ?? 'info';
	if (isset($groupedChecks[$status]))
	{
		$groupedChecks[$status][] = $check;
	}
}

// Status configuration for icons and styling
$statusConfig = [
	'good'    => [
		'icon'  => 'check-circle',
		'class' => 'text-success',
		'label' => Text::_('COM_ALFA_SEO_STATUS_GOOD')
	],
	'warning' => [
		'icon'  => 'warning',
		'class' => 'text-warning',
		'label' => Text::_('COM_ALFA_SEO_STATUS_WARNING')
	],
	'error'   => [
		'icon'  => 'warning',
		'class' => 'text-danger',
		'label' => Text::_('COM_ALFA_SEO_STATUS_ERROR')
	],
	'info'    => [
		'icon'  => 'info-circle',
		'class' => 'text-info',
		'label' => Text::_('COM_ALFA_SEO_STATUS_INFO')
	]
];
?>

<div class="seo-preview-container mt-4 card border-0 shadow-sm" data-seo-preview-container>
    <!-- Header with Score Badge -->
    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3"
         data-seo-preview-header>
        <h5 class="mb-0 fw-semibold">
            <span class="icon-search text-primary me-2"></span>
			<?php echo Text::_('COM_ALFA_SEO_PREVIEW_TITLE'); ?>
        </h5>
        <span class="badge rounded-pill px-3 py-2 fw-semibold"
              style="background-color: <?php echo $scoreColor; ?>; font-size: 14px; letter-spacing: 0.5px;"
              data-seo-score-badge
              title="<?php echo htmlspecialchars($scoreText, ENT_QUOTES, 'UTF-8'); ?>">
				<?php echo $score; ?>/100
			</span>
    </div>

    <div class="p-3" data-seo-preview-search>
        <div class="d-flex justify-content-between align-items-center my-3">
            <h6 class="text-muted small text-uppercase mb-0 fw-semibold">
                <span class="icon-eye me-1"></span>
				<?php echo Text::_('COM_ALFA_SEO_GOOGLE_PREVIEW'); ?>
            </h6>

            <button type="button"
                    class="btn btn-sm btn-link text-decoration-none p-0"
                    data-seo-refresh-btn
                    title="<?php echo htmlspecialchars(Text::_('COM_ALFA_SEO_REFRESH'), ENT_QUOTES, 'UTF-8'); ?>">
                <span class="icon-refresh"></span>
                <span class="small"><?php echo Text::_('COM_ALFA_SEO_REFRESH'); ?></span>
            </button>
        </div>

        <!-- Focus Keyword Input (UI Only - Google Style Search) -->
        <div class="seo-focus-keyword-wrapper">
            <div class="input-group">
                <span class="input-group-text">
                    <span class="icon-key"></span>
                </span>
                <input type="text"
                       class="form-control"
                       id="seo-focus-keyword-input"
                       data-seo-focus-keyword-field
                       placeholder="<?php echo htmlspecialchars(Text::_('COM_ALFA_SEO_FOCUS_KEYWORD_PLACEHOLDER'), ENT_QUOTES, 'UTF-8'); ?>"
                       value="<?php echo htmlspecialchars($focusKeyword, ENT_QUOTES, 'UTF-8'); ?>"
                       aria-label="<?php echo htmlspecialchars(Text::_('COM_ALFA_SEO_FOCUS_KEYWORD_LABEL'), ENT_QUOTES, 'UTF-8'); ?>">
                <span class="input-group-text">
                    <?php echo Text::_('COM_ALFA_SEO_FOCUS_KEYWORD_HINT'); ?>
                </span>
            </div>
            <small class="form-text text-muted d-block">
                <span class="icon-info-circle"></span>
				<?php echo Text::_('COM_ALFA_SEO_FOCUS_KEYWORD_DESCRIPTION'); ?>
            </small>
        </div>
    </div>

    <div class="card-body p-3" data-seo-preview-result>

        <div class="mb-4">
            <div class="seo-google-preview p-3 rounded">
                <div class="seo-preview-url mb-1">
                    <span style="color: #5f6368;">https://</span>
                    <span style="color: #202124;"
                          data-seo-domain><?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="color: #5f6368;"
                          data-seo-path><?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="seo-preview-title mb-1">
                    <span data-seo-title><?php echo htmlspecialchars($titleDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="seo-preview-description">
                    <span class="seo-date"><?php echo $currentDate; ?> — </span>
                    <span data-seo-description><?php echo htmlspecialchars($descDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </div>

        <!-- SEO Analysis Section -->
        <div class="seo-analysis">
            <h6 class="mb-3 fw-semibold">
                <span class="icon-chart me-1"></span>
				<?php echo Text::_('COM_ALFA_SEO_ANALYSIS_TITLE'); ?>
            </h6>

			<?php if (!empty($analysis)) : ?>
                <div class="seo-checks-grouped" data-seo-checks>
					<?php
					// Display checks in priority order: errors, warnings, good, info
					$displayOrder = ['error', 'warning', 'good', 'info'];

					foreach ($displayOrder as $status) :
						if (empty($groupedChecks[$status])) continue;

						$config = $statusConfig[$status];
						?>

                        <div class="check-group mb-3">
							<?php foreach ($groupedChecks[$status] as $check) : ?>
                                <div class="d-flex align-items-start mb-2 p-2 rounded seo-check-item"
                                     style="transition: background-color 0.2s;">
										<span class="icon-<?php echo $config['icon']; ?> <?php echo $config['class']; ?> me-2 flex-shrink-0"
                                              style="font-size: 18px;"></span>
                                    <span class="small flex-grow-1"><?php echo htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
							<?php endforeach; ?>
                        </div>

					<?php endforeach; ?>
                </div>

                <!-- Optional: Show focus keyword if set -->
				<?php if (!empty($focusKeyword)) : ?>
                    <div class="alert alert-info alert-sm d-flex align-items-center mt-3 mb-0"
                         style="font-size: 0.875rem;">
                        <span class="icon-key me-2"></span>
                        <span>
								<strong><?php echo Text::_('COM_ALFA_SEO_FOCUS_KEYWORD'); ?>:</strong>
								<?php echo htmlspecialchars($focusKeyword, ENT_QUOTES, 'UTF-8'); ?>
							</span>
                    </div>
				<?php endif; ?>

			<?php else : ?>
                <div class="alert alert-light d-flex align-items-center" role="alert">
                    <span class="icon-info-circle me-2 text-muted"></span>
                    <span class="text-muted">
							<?php echo Text::_('COM_ALFA_SEO_NO_ANALYSIS'); ?>
						</span>
                </div>
			<?php endif; ?>
        </div>

    </div>
</div>