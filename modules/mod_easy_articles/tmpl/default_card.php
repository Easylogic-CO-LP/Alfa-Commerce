<?php

/**
 * @package    Easy Articles
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One article card: image, title, meta line, teaser and a read-more cue. The
 * title's link is the card's only link and covers the whole card, so the card is
 * one tab stop named by the article's title. The image carries its intrinsic
 * size where the media manager recorded it, and its frame keeps its ratio, so
 * nothing moves while it loads. The card names its place (`data-look`, in
 * $view['attributes']); how it looks there, on every screen and per breakpoint, is
 * in the rules the module writes for that place (CardLook::css). A part (the date,
 * the category, the author, the intro text, read more) is in the page when any
 * breakpoint shows it; those rules hide it elsewhere by its class.
 *
 * @var object $article The article (a com_content ArticlesModel row)
 * @var array $view    Prepared card data (EasyArticlesHelper::cardData)
 * @var array $card    The card of its place (EasyArticlesHelper::card)
 * @var bool $eager   Load the image with the page
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$heading = $card['heading'];
$image = $view['image'];
$meta = array_filter(['category' => $view['category'], 'author' => $view['author']], static fn (string $text): bool => $text !== '');
?>
<article class="teaser"<?php echo $view['attributes']; ?>>
    <?php if ($image) : ?>
        <div class="teaser-image">
            <img src="<?php echo $esc($image['src']); ?>"
                 alt="<?php echo $esc($image['alt']); ?>"<?php echo $image['width'] > 0 && $image['height'] > 0 ? ' width="' . $image['width'] . '" height="' . $image['height'] . '"' : ''; ?>
                 loading="<?php echo $eager ? 'eager' : 'lazy'; ?>"
                 decoding="async"<?php echo $eager ? ' fetchpriority="high"' : ''; ?>>
        </div>
    <?php endif; ?>
    <div class="teaser-body">
        <<?php echo $heading; ?> class="teaser-title">
            <a href="<?php echo $esc($view['link']); ?>"><?php echo $esc($view['title']); ?></a>
        </<?php echo $heading; ?>>
        <?php if ($view['date'] || $meta !== []) : ?>
            <p class="teaser-meta">
                <?php if ($view['date']) : ?>
                    <time class="teaser-date" datetime="<?php echo $esc($view['date']['iso']); ?>"><?php echo $esc($view['date']['text']); ?></time>
                <?php endif; ?>
                <?php foreach ($meta as $part => $text) : ?>
                    <span class="teaser-<?php echo $part; ?>"><?php echo $esc($text); ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <?php if ($view['intro'] !== '') : ?>
            <p class="teaser-intro"><?php echo $esc($view['intro']); ?></p>
        <?php endif; ?>
        <?php if ($card['readmore']) : ?>
            <span class="teaser-more" aria-hidden="true">
                <?php echo Text::_('MOD_EASY_ARTICLES_READ_MORE'); ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
        <?php endif; ?>
    </div>
</article>
