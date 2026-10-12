<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Pagination;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Pagination\Pagination;

/**
 * Pagination whose page links are built from a DECLARED base, never from the request.
 *
 * A page link addresses THE LISTING. It has no business describing the request that happened to
 * render it. Joomla 5.2 blurred that: {@see Pagination::setUrlParamsFromRequest()}, called as the
 * last statement of the parent constructor, copies whatever the router holds for twelve keys (
 * format, option, controller, view, layout, task, template, templateStyle, tmpl, tpl, id, Itemid)
 * into every href the object emits. That is harmless while the request IS the page, and wrong
 * the moment the same view is rendered for something else.
 *
 * The filter modules re-render this listing at `?tmpl=component` to swap the results in place, so
 * every page link came back carrying it, and one click dropped the shopper on the bare component
 * template: no header, no menu, no filter panel.
 *
 * So the harvest does not run at all here. The base is declared by the model
 * ({@see \Alfa\Component\Alfa\Site\Model\UrlListModel::getLinkBase()}) and the request gets no
 * vote, which means a partial render and a document render emit byte-identical hrefs, and a key
 * core adds to that list in a future release cannot reopen this.
 *
 * @since  1.0.0
 */
class AlfaPagination extends Pagination
{
    /**
     * Constructor.
     *
     * @param int $total The total number of items.
     * @param int $limitstart The offset of the item to start at.
     * @param int $limit The number of items to display per page.
     * @param string $prefix The prefix used for request variables.
     * @param ?CMSApplication $app The application object.
     * @param array $linkBase The declared query every page link is built on.
     *
     * @since   1.0.0
     */
    public function __construct(
        $total,
        $limitstart,
        $limit,
        $prefix = '',
        ?CMSApplication $app = null,
        array $linkBase = [],
    ) {
        // The base is installed AFTER the parent constructor, not before it. The parent ends by
        // calling setUrlParamsFromRequest() (overridden below to harvest nothing) so there is
        // nothing for a pre-assigned property to feed, and the contract stays readable in one
        // direction: the parent builds the counters, this constructor declares the address.
        parent::__construct($total, $limitstart, $limit, $prefix, $app);

        foreach ($linkBase as $key => $value) {
            $this->setAdditionalUrlParam($key, $value);
        }

        // `option` is forced, never merely conventioned. A link with no option and no Itemid is
        // `index.php?&limitstart=N`, and that is the one shape SiteRouter::buildInit() merges the
        // WHOLE router var bag into, which would republish tmpl, and everything else on the
        // request, as the listing's own address. Strictly worse than the defect this class fixes.
        if (!$this->getAdditionalUrlParam('option')) {
            $this->setAdditionalUrlParam('option', 'com_alfa');
        }
    }

    /**
     * Harvest nothing from the request.
     *
     * Deliberately empty: this class declares its base instead. Overriding the harvest rather
     * than deleting keys after it is what makes the base an ALLOW-LIST: the twelve keys core
     * copies today, and any key it adds tomorrow, are covered by the same absence.
     *
     * @return void
     * @since   1.0.0
     */
    protected function setUrlParamsFromRequest()
    {
    }
}
