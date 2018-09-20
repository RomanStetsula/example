<?php

namespace Crawler\Services\Parser;

use Log;
use SimplePie;
use Crawler\SDO\Language;
use Crawler\SDO\Location;
use Crawler\SDO\Post;
use Crawler\SDO\WebCrawlerSource;
use Smarty\Nodes\Crawler\SmartyPage;
use Crawler\Services\Parser\Tools\UrlManipulator;
use Crawler\Services\Parser\Tools\Timer;
use Crawler\SDO\Provider;

class RssListPageParser
{
    /** 
     * SimplePie Client timeout (seconds) 
     */ 
    const TIMEOUT = 10; 

    /**
     * The page to extract url from.
     *
     * @var string
     */
    public $page;

    /**
     * The Rss reader instance.
     *
     * @var \Symfony\Component\CssSelector\Parser\Reader
     */
    public $reader;

    /**
     * Create a new instance of this class.
     *
     * @param SmartyPage $page
     */
    public function __construct($page)
    {
        $this->page = $page;
        $this->timer = new Timer();
        $this->reader = new SimplePie();
        $this->reader->set_timeout(self::TIMEOUT); 

        $this->setResource($page->getUrl());
    }

    /**
     * Pareses the resource for urls.
     *
     * @return array
     * @throws \Exception
     */
    public function parse()
    {
        $this->timer->start();

        $urlManipulator = new UrlManipulator();
        $parser = $this->prepareParser();

        try{
            $parser->init(); // returns feed object
        } catch (\Exception $exception){
            Log::warning($exception->getMessage());
            return [];
        }

        $pageUrls = [];

        foreach ($this->getAllItems($parser) as $item) {
            $post = new Post();
            $post->setOriginUrl($urlManipulator->setUrl($item->get_link())->normalize()->get());

            $language = (new Language())->setProvider('editor')->setCode($this->page->getLanguage());

            $domain = $this->page->getDomain();
            $location = (new Location())->setName($domain->getLocation())
                    ->setLatitude($domain->getLatitude())
                    ->setLongitude($domain->getLongitude());

            $webCrawlerSource = (new WebCrawlerSource())
                ->setDomainId($domain->getId())
                ->setDomain($domain->getName())
                ->setPageId($this->page->getId())
                ->setPageName($this->page->getName())
                ->setCategory($this->page->getCategories()->asArray())
                ->setSubcategory($this->page->getSubcategories()->asArray());

            $post->setLanguage($language);
            $post->setLocation($location);
            $post->setWebCrawlerSource($webCrawlerSource);

            $pageUrls[] = $post;
        }

        if(empty($pageUrls)){
             return $parser->raw_data;
        } else {
            $this->timer->stop();
            $this->log('RSS Page links parsed successfully', 1);

            return $pageUrls;
        }
    }

    /**
     * @param $url
     * @return bool
     */
    private function setResource($url)
    {
        $this->reader->set_feed_url($url);
        return true;
    }

    /**
     * Prepare the parser.
     */
    private function prepareParser()
    {
        $this->reader->enable_cache(false);
        return $this->reader;
    }

    /**
     * Returns all feed items.
     *
     * @param $parser
     *
     * @return
     */
    private function getAllItems($parser)
    {
        return $parser->get_items(0, $parser->get_item_quantity());
    }

    /**
     * Log.
     *
     * @param $message
     * @param int $success
     *
     * @throws \Exception
     */
    private function log($message, $success = 0)
    {
        if(env('ENABLE_LOGGING')) {
            Log::notice($message, [
                'success'       => $success,
                'page_id'       => $this->page->getId(),
                'page_url'      => $this->page->getUrl(),
                'app_name'      => 'Crawler2.0',
                'class_name'    => 'Services\Parser\RssListParser',
                'process_time'  => $this->timer->result(),
            ]);
        }
    }
}
