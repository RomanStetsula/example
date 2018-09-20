<?php

namespace Crawler\Services\Parser;

use Smarty\Nodes\Crawler\SmartyPage;

class ListPageParser
{
    /**
     * The SmartyPage instance.
     *
     * @var \Smarty\Nodes\Crawler\SmartyPage
     */
    private $page;

    /**
     * Create a new instance of this class.
     *
     * @param SmartyPage $page
     */
    public function __construct(SmartyPage $page)
    {
        $this->page = $page;
    }

    /**
     * Parses the resource for urls.
     *
     * @return array
     */
    public function parse()
    {
        $parser = null;

        // Detect the type of the list page and assign the right parser for the job.
        switch ($this->page->getFormat()) {
            case 'rss':
                $parser = new RssListPageParser($this->page);
                break;
            case 'html':
                $parser = new HtmlListPageParser($this->page);
                break;
        }

        // Parse and dispatch the job if the type is supported.
        if (!$parser)
            return [];

        return $parser->parse();
    }
}
