<?php

/**
 * @package     Com_Alfa
 * @subpackage  Administrator
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @version     1.0.1
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\AlfaHelper as FrontendAlfaHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * SEO Preview Controller - Full UTF-8 Support
 *
 * @since  2.2.0
 */
class SeoController extends BaseController
{
    /**
     * Update SEO preview HTML via AJAX
     *
     * @return void
     *
     * @since   1.0.0
     */
    public function getPreview()
    {
        // Ensure UTF-8 encoding for JSON response
        header('Content-Type: application/json; charset=utf-8');

        // Check for request forgeries
        $this->checkToken();

        try {
            $app = Factory::getApplication();
            $input = $app->getInput();

            // Get form data with proper UTF-8 handling
            $title = $input->getString('title', '');
            $metaTitle = $input->getString('metaTitle', '');
            $metaDesc = $input->getString('metaDesc', '');
            $defaultAlias = $input->getString('defaultAlias', '');
            $alias = $input->getString('alias', '');
            $content = $input->getString('content', '');

            $additionalContent = $input->get('additionalContent', [], 'array');
            // Sanitize each field
            array_walk($additionalContent, function (&$value) {
                $value = strip_tags($value ?? '');
            });

            $focusKeyword = $input->getString('focusKeyword', '');
            $robots = $input->getString('robots', '');
            $itemId = $input->getInt('itemId', 0);
            $itemType = $input->getString('itemType', 'category');

            $displayData = $this->getResultObject(
                itemId: $itemId,
                title: $title,
                metaTitle: $metaTitle,
                metaDesc: $metaDesc,
                alias: $alias,
                defaultAlias: $defaultAlias,
                content: $content,
                additionalContent: $additionalContent,
                focusKeyword: $focusKeyword,
                itemType: $itemType,
                robots: $robots,
                // maybe add fieldJsSelectors maybe, but we set them on initial preview
            );

            // Render layout
            $html = LayoutHelper::render('seo.preview', $displayData);

            // Return JSON response with alias included
            echo new JsonResponse([
                'html' => $html,
            ], Text::_('COM_ALFA_SEO_PREVIEW_SUCCESS'), false);
        } catch (Exception $e) {
            echo new JsonResponse($e, Text::_('COM_ALFA_SEO_PREVIEW_ERROR'), true);
        }

        $app->close();
    }

    /**
     * Get initial SEO preview data for PHP rendering
     *
     *
     * @return object Display data for layout
     *
     * @since   2.2.0
     */
    public function getResultObject($itemId = 0, $title = '', $metaTitle = '', $metaDesc = '', $alias = '', $defaultAlias = '', $content = '', $additionalContent = null, $focusKeyword = '', $itemType = 'category', $robots = '', $fieldJsSelectors = null)
    {
        $app = Factory::getApplication();
        $config = $app->getConfig();
        $siteName = $config->get('sitename');
        $siteNameOnPageTitle = $app->get('sitename_pagetitles', 0);

        // Get values from item
        $title ??= '';
        $metaTitle = $metaTitle ?: $title;
        $metaDesc ??= '';
        $alias ??= '';
        $defaultAlias ??= '';
        $content ??= '';
        $additionalContent ??= [];
        $focusKeyword ??= '';
        $itemId ??= 0;

        if (!empty($siteNameOnPageTitle)) {
            if ($siteNameOnPageTitle == 1) { // Before
                $metaTitle = $siteName . ' - ' . $metaTitle;
            } else { // After
                $metaTitle = $metaTitle . ' - ' . $siteName;
            }
        }

        // Generate alias if empty
        if (empty($alias) && !empty($title)) {
            $alias = $this->generateAlias($title);
        }

        // Generate URL preview
        $previewUrl = $realUrl = $this->getPreviewUrl($itemId, $itemType);
        // Replace previous alias with the new one, if previous exists because the getPreview fetches the real url with Router
        // so it works only if the alias is saved in db and the frontend router knows it
        // so we dynamically change it to be more user friendly
        if (!empty($defaultAlias) && $defaultAlias !== $alias) {
            // This assumes the alias appears as a path segment in the URL
            $previewUrl = str_replace('/' . $defaultAlias, '/' . $alias, $previewUrl);
        }

        $combinedContent = $this->combineContent($content, $additionalContent);

        // Run SEO analysis
        $analysis = $this->analyzeSEO($title, $metaTitle, $metaDesc, $alias, $content, $combinedContent, $focusKeyword, $robots);

        // Calculate overall score
        $score = $this->calculateScore($analysis);

        $displayData = (object) [
            'url' => $previewUrl,
            'realUrl' => $realUrl,
            'title' => $title,
            'metaTitle' => $metaTitle,
            'metaDesc' => $metaDesc,
            'defaultAlias' => $defaultAlias,
            'alias' => $alias,
            'content' => $content,
            'additionalContent' => $additionalContent,
            'focusKeyword' => $focusKeyword,
            'robots' => $robots,
            'siteName' => $siteName,
            'analysis' => $analysis,
            'score' => $score,
            'itemId' => $itemId,
            'itemType' => $itemType,
            'fieldJsSelectors' => $fieldJsSelectors,
        ];

        return $displayData;
    }

    /**
     * Combine multiple content fields for SEO analysis
     *
     * @param string $mainContent Main content field
     * @param array $additionalContent Additional content fields (key => value)
     *
     * @return string Combined content
     *
     * @since   2.2.0
     */
    protected function combineContent($mainContent, $additionalContent = [])
    {
        // Start with main content
        $combined = trim($mainContent);

        // Add additional content fields
        if (is_array($additionalContent) && !empty($additionalContent)) {
            foreach ($additionalContent as $fieldName => $fieldContent) {
                $fieldContent = trim($fieldContent);

                if (!empty($fieldContent)) {
                    // Add separator if combined content is not empty
                    if (!empty($combined)) {
                        $combined .= '. ';
                    }

                    $combined .= $fieldContent;
                }
            }
        }

        return $combined;
    }

    /**
     * Get preview URL using Joomla's router
     *
     * @param int $itemId Item ID
     * @param string $itemType Type of item
     *
     * @return string Full preview URL
     *
     * @since   2.0.0
     */
    protected function getPreviewUrl($itemId, $itemType = 'category')
    {
        // Build the route based on item type
        if ($itemType === 'category') {
            $route = 'index.php?option=com_alfa&view=items&catid=' . (int) $itemId;
        } else {
            $route = 'index.php?option=com_alfa&view=item&id=' . (int) $itemId;
        }

        // Try Route::link() first (Joomla 4+)
        $url = Route::link('site', $route, false);

        // Ensure it's absolute
        if (!preg_match('#^https?://#', $url)) {
            $url = Uri::root() . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Analyze SEO factors with full UTF-8 support
     *
     * @param string $title Page title
     * @param string $metaTitle Meta title
     * @param string $metaDesc Meta description
     * @param string $alias URL alias
     * @param string $content Content
     * @param string $focusKeyword Focus keyword
     *
     * @return array Analysis results
     *
     * @since   2.2.0
     */
    protected function analyzeSEO($title, $metaTitle, $metaDesc, $alias, $content, $combinedContent, $focusKeyword = '', $robots = '')
    {
        $checks = [];

        $contentToAnalyze = trim(trim($content) . (!empty(trim($combinedContent)) ? '. ' . trim($combinedContent) : ''));

        // Match frontend behavior: use title if metaTitle is empty
        $effectiveTitle = trim($metaTitle) !== '' ? $metaTitle : $title;

        // Use mb_strlen for proper UTF-8 character counting
        $titleLength = mb_strlen($effectiveTitle, 'UTF-8');

        // Add note if using fallback title
        if (trim($metaTitle) === '' && !empty($title)) {
            $checks[] = [
                'status' => 'info',
                'message' => Text::_('COM_ALFA_SEO_CHECK_USING_TITLE_AS_META'),
                'score' => 90,
            ];
        }

        // Title length check
        if (empty($effectiveTitle)) {
            $checks[] = [
                'status' => 'error',
                'message' => Text::_('COM_ALFA_SEO_CHECK_TITLE_MISSING'),
                'score' => 0,
            ];
        } elseif ($titleLength < 30) {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_TITLE_SHORT', $titleLength),
                'score' => 30,
            ];
        } elseif ($titleLength > 60) {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_TITLE_LONG', $titleLength),
                'score' => 70,
            ];
        } else {
            $checks[] = [
                'status' => 'good',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_TITLE_GOOD', $titleLength),
                'score' => 100,
            ];
        }

        $effectiveDescription = trim($metaDesc) !== '' ? $metaDesc : $content;

        // Meta description check
        if (trim($metaDesc) === '' && !empty($content)) {
            $checks[] = [
                'status' => 'info',
                'message' => Text::_('COM_ALFA_SEO_CHECK_USING_CONTENT_AS_META'),
                'score' => 90,
            ];
        }

        $metaDesc = FrontendAlfaHelper::cleanContent(
            html: $effectiveDescription,
            removeTags: true,
            removeScripts: true,
            removeIsolatedPunctuation: false,
        );

        $descLength = mb_strlen($effectiveDescription, 'UTF-8');
        if (empty($effectiveDescription)) {
            $checks[] = [
                'status' => 'error',
                'message' => Text::_('COM_ALFA_SEO_CHECK_DESC_MISSING'),
                'score' => 0,
            ];
        } elseif ($descLength < 120) {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_DESC_SHORT', $descLength),
                'score' => 40,
            ];
        } elseif ($descLength > 160) {
            $checks[] = [
                'status' => 'info',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_DESC_LONG', $descLength),
                'score' => 80,
            ];
        } else {
            $checks[] = [
                'status' => 'good',
                'message' => Text::sprintf('COM_ALFA_SEO_CHECK_DESC_GOOD', $descLength),
                'score' => 100,
            ];
        }

        // URL/Alias check
        if (empty($alias)) {
            $checks[] = [
                'status' => 'info',
                'message' => Text::_('COM_ALFA_SEO_CHECK_ALIAS_AUTO'),
                'score' => 60,
            ];
        } elseif (mb_strlen($alias, 'UTF-8') > 100) {
            // Increased limit to 100 chars for better flexibility
            $checks[] = [
                'status' => 'warning',
                'message' => Text::_('COM_ALFA_SEO_CHECK_ALIAS_LONG'),
                'score' => 60,
            ];
        } elseif (!preg_match('/^[a-z0-9\-_]+$/', $alias)) {
            // Check for valid URL characters (Joomla already normalized it)
            $checks[] = [
                'status' => 'warning',
                'message' => Text::_('COM_ALFA_SEO_CHECK_ALIAS_SPECIAL'),
                'score' => 50,
            ];
        } else {
            $checks[] = [
                'status' => 'good',
                'message' => Text::_('COM_ALFA_SEO_CHECK_ALIAS_GOOD'),
                'score' => 100,
            ];
        }

        // Content length check
        if (empty($contentToAnalyze)) {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::_('COM_ALFA_SEO_CHECK_CONTENT_MISSING'),
                'score' => 60,
            ];
        } else {
            $wordCount = $this->word_count($contentToAnalyze);
            $wordLimit = 100;

            if ($wordCount < $wordLimit) {
                $checks[] = [
                    'status' => 'warning',
                    'message' => Text::sprintf('COM_ALFA_SEO_CHECK_CONTENT_SHORT', $wordCount, $wordLimit),
                    'score' => 40,
                ];
            } else {
                $checks[] = [
                    'status' => 'good',
                    'message' => Text::sprintf('COM_ALFA_SEO_CHECK_CONTENT_GOOD', $wordCount),
                    'score' => 100,
                ];
            }

            // Readability check
            $readability = $this->calculateReadability($contentToAnalyze);
            if ($readability !== null) {
                $checks[] = $readability;
            }
        }

        // Focus keyword analysis (if provided)
        if (!empty($focusKeyword)) {
            $keywordChecks = $this->analyzeFocusKeyword($focusKeyword, $effectiveTitle, $metaDesc, $alias, $contentToAnalyze);
            $checks = array_merge($checks, $keywordChecks);
        }

        // Robots meta tag check
        if (!empty($robots)) {
            if ($robots === 'noindex, follow') {
                $checks[] = [
                    'status' => 'warning',
                    'message' => Text::_('COM_ALFA_SEO_ROBOTS_NOINDEX_FOLLOW_DESC'),
                    'score' => 30,
                ];
            } elseif ($robots === 'noindex, nofollow') {
                $checks[] = [
                    'status' => 'error',
                    'message' => Text::_('COM_ALFA_SEO_ROBOTS_NOINDEX_NOFOLLOW_DESC'),
                    'score' => 0,
                ];
            } elseif ($robots === 'index, follow') {
                $checks[] = [
                    'status' => 'good',
                    'message' => Text::_('COM_ALFA_SEO_ROBOTS_INDEX_FOLLOW_DESC'),
                    'score' => 100,
                ];
            } elseif ($robots === 'index, nofollow') {
                $checks[] = [
                    'status' => 'warning',
                    'message' => Text::_('COM_ALFA_SEO_ROBOTS_INDEX_NOFOLLOW_DESC'),
                    'score' => 60,
                ];
            } else {
                // For any custom/unknown robots value
                $checks[] = [
                    'status' => 'warning',
                    'message' => Text::sprintf('COM_ALFA_SEO_CHECK_ROBOTS_CUSTOM', $robots),
                    'score' => 50,
                ];
            }
        } else {
            $checks[] = [
                'status' => 'info',
                'message' => Text::_('COM_ALFA_SEO_ROBOTS_GLOBAL_DESC'),
                'score' => 100,
            ];
        }

        return $checks;
    }

    /**
     * Analyze focus keyword usage with full UTF-8 support
     *
     * @param string $keyword Focus keyword
     * @param string $title Effective title
     * @param string $metaDesc Meta description
     * @param string $alias URL alias
     * @param string $content Content
     *
     * @return array Keyword analysis results
     *
     * @since   2.2.0
     */
    protected function analyzeFocusKeyword($keyword, $title, $metaDesc, $alias, $content)
    {
        $checks = [];
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');

        if (empty($keyword)) {
            return $checks;
        }

        $keywordAlias = $this->generateAlias($keyword);
        $titleAlias = $this->generateAlias($title);
        $metaDescAlias = $this->generateAlias($metaDesc);

        // Keyword in title
        if (mb_stripos($titleAlias, $keywordAlias, 0, 'UTF-8') !== false) {
            // Check if it's at the beginning
            if (str_starts_with($titleAlias, $keywordAlias)) {
                $checks[] = [
                    'status' => 'good',
                    'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_IN_TITLE_START'),
                    'score' => 100,
                ];
            } else {
                $checks[] = [
                    'status' => 'good',
                    'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_IN_TITLE'),
                    'score' => 90,
                ];
            }
        } else {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_NOT_IN_TITLE'),
                'score' => 40,
            ];
        }

        // Keyword in description
        if (mb_stripos($metaDescAlias, $keywordAlias, 0, 'UTF-8') !== false) {
            $checks[] = [
                'status' => 'good',
                'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_IN_DESC'),
                'score' => 100,
            ];
        } else {
            $checks[] = [
                'status' => 'warning',
                'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_NOT_IN_DESC'),
                'score' => 50,
            ];
        }

        // Keyword in URL (using normalized alias)
        if (mb_stripos($alias, $keywordAlias, 0, 'UTF-8') !== false) {
            $checks[] = [
                'status' => 'good',
                'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_IN_URL'),
                'score' => 100,
            ];
        } else {
            $checks[] = [
                'status' => 'info',
                'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_NOT_IN_URL'),
                'score' => 70,
            ];
        }

        // Keyword density in content
        if (!empty($content)) {
            // clean the content first
            $contentText = FrontendAlfaHelper::cleanContent(
                html: $content,
                removeTags: true,
                removeScripts: true,
                removeIsolatedPunctuation: false,
            );

            // count the words
            $wordCount = $this->word_count($contentText);

            // count keyword density in content
            $contentTextAlias = $this->generateAlias($content);
            $keywordCount = substr_count($contentTextAlias, $keywordAlias);

            if ($wordCount > 0) {
                $density = ($keywordCount / $wordCount) * 100;

                if ($keywordCount === 0) {
                    $checks[] = [
                        'status' => 'error',
                        'message' => Text::_('COM_ALFA_SEO_CHECK_KEYWORD_NOT_IN_CONTENT'),
                        'score' => 0,
                    ];
                } elseif ($density >= 0.5 && $density <= 2.5) {
                    $checks[] = [
                        'status' => 'good',
                        'message' => Text::sprintf('COM_ALFA_SEO_CHECK_KEYWORD_DENSITY_GOOD', round($density, 2)),
                        'score' => 100,
                    ];
                } elseif ($density > 2.5) {
                    $checks[] = [
                        'status' => 'warning',
                        'message' => Text::sprintf('COM_ALFA_SEO_CHECK_KEYWORD_DENSITY_HIGH', round($density, 2)),
                        'score' => 60,
                    ];
                } else {
                    $checks[] = [
                        'status' => 'info',
                        'message' => Text::sprintf('COM_ALFA_SEO_CHECK_KEYWORD_DENSITY_LOW', round($density, 2)),
                        'score' => 70,
                    ];
                }
            }
        }

        return $checks;
    }

    /**
     * Calculate readability score
     *
     * @param string $text Content text
     *
     * @return array|null Readability check result
     *
     * @since   2.0.0
     */
    protected function calculateReadability($text)
    {
        if (empty($text)) {
            return null;
        }

        // Split into sentences
        $text = FrontendAlfaHelper::cleanContent(
            html: $text,
            removeTags: true,
            removeScripts: true,
            removeIsolatedPunctuation: false,
        );

        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = $this->word_count($text);
        $sentenceCount = count($sentences);

        if ($sentenceCount === 0 || $words === 0) {
            return null;
        }

        $avgWordsPerSentence = $words / $sentenceCount;

        // Simple readability assessment
        if ($avgWordsPerSentence <= 15) {
            return [
                'status' => 'good',
                'message' => Text::sprintf('COM_ALFA_SEO_READABILITY_EASY', round($avgWordsPerSentence, 1)),
                'score' => 100,
            ];
        } elseif ($avgWordsPerSentence <= 20) {
            return [
                'status' => 'info',
                'message' => Text::sprintf('COM_ALFA_SEO_READABILITY_MODERATE', round($avgWordsPerSentence, 1)),
                'score' => 85,
            ];
        } else {
            return [
                'status' => 'warning',
                'message' => Text::sprintf('COM_ALFA_SEO_READABILITY_DIFFICULT', round($avgWordsPerSentence, 1)),
                'score' => 60,
            ];
        }
    }

    /**
     * Calculate overall SEO score
     *
     * @param array $checks Analysis checks
     *
     * @return int Score (0-100)
     *
     * @since   1.0.0
     */
    protected function calculateScore($checks)
    {
        if (empty($checks)) {
            return 0;
        }

        $totalScore = 0;
        foreach ($checks as $check) {
            $totalScore += $check['score'];
        }

        return round($totalScore / count($checks));
    }

    /**
     * Generate URL alias from title using Joomla's built-in function
     *
     * @param string $title The title
     *
     * @return string The alias
     *
     * @since   1.0.0
     */
    protected function generateAlias($title)
    {
        return OutputFilter::stringURLSafe($title);
    }

    /**
     * Returns the number of words in a string.
     */
    public function word_count(string $html, bool $cleanHtml = true): int
    {
        // Use the cleaner to prepare text
        $cleanText = FrontendAlfaHelper::cleanContent($html, true, true, true);

        // If the text is empty after cleaning, return 0
        if ($cleanText === '' || $cleanText === null) {
            return 0;
        }

        // Split the text into words
        $words = explode(' ', $cleanText);

        // Remove any empty entries
        $words = array_filter($words);

        // Return total word count
        return count($words);
    }
}
