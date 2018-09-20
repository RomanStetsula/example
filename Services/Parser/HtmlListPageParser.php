<?php

namespace Crawler\Services\Parser;

use Log;
use Bowtie\Grawler\Client;
use Crawler\SDO\Language;
use Crawler\SDO\Location;
use Crawler\SDO\Post;
use Crawler\SDO\WebCrawlerSource;
use Crawler\Services\Parser\Tools\Timer;
use Crawler\Services\Parser\Tools\UrlManipulator;
use Crawler\Services\Parser\Exceptions\AreasNotAvailableException;
use Smarty\Nodes\Crawler\SmartyArea;
use Smarty\Nodes\Crawler\SmartyPage;
use GuzzleHttp\Client as GuzzleClient;
use Crawler\SDO\Provider;

class HtmlListPageParser
{
    /** 
     * Guzzle Client connect timeout (seconds) 
     */ 
    const CONNECT_TIMEOUT = 5;

    /** 
     * Guzzle Client request timeout (seconds) 
     */ 
    const REQUEST_TIMEOUT = 20;

    /**
     * The page areas where links exists.
     *
     * @var string
     */
    public $areas;

    /**
     * The page to extract url from.
     *
     * @var string
     */
    public $page;

    /**
     * The page url.
     *
     * @var string
     */
    public $url;

    /**
     * @var \Bowtie\Grawler\Grawler
     */
    protected $grawler;

    /**
     * Create a new instance of this class.
     *
     * @param SmartyPage $page
     * @param null       $area
     */
    public function __construct(SmartyPage $page, $area = null)
    {
        $this->page = $page;
        $this->areas = $area ? [$area] : $page->getAreas();
        $this->timer = new Timer();
        $this->url = $page->getUrl();
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function parse()
    {
        $guzzleClient = new GuzzleClient(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::REQUEST_TIMEOUT]);
        $client = new Client();
        $client->setClient($guzzleClient);
        try{
            $this->grawler = $client->download($this->url);
        } catch (\Exception $exception){
            Log::warning($exception->getMessage());
            return [];
        }

        return $this->getPageUrls();
    }


    /**
     * @return array
     * @throws \Exception
     */
    private function getPageUrls()
    {
        $this->timer->start();
        $pageUrls = [];
        // If no areas provided for this page use the auto extractor
        if (!count($this->areas)){
            $pageUrls = $this->autoExtractUrls();
            $pageUrls['error']['area'] = 'Area';

            return $pageUrls;
        }

        foreach ($this->areas as $area) {
            $areaUrls = $this->getAreaUrls($area);
            $pageUrls = array_merge($pageUrls, $areaUrls);
        }

         if(empty($pageUrls)){
             $pageUrls['error']['path'] = 'Section Path';
         }

        $this->timer->stop();
        $this->log('HTML Page links parsed successfully', 1);

        return $pageUrls;
    }

    /**
     * Parses the DOM area for urls.
     *
     * @param SmartyArea $area
     *
     * @return mixed
     */
    private function getAreaUrls($area)
    {
        try{
            $areaUrls = $this->grawler->links($area->getSectionPath());
        } catch(\Exception $exception){
            Log::warning($exception->getMessage());
            return [];
        }
        $areaUrls = array_unique($areaUrls);

        // Get full path URLs
        foreach ($areaUrls as $key => $areaUrl)
            $areaUrls[$key] = $areaUrl;

        $formattedAreaUrl = [];

        foreach ($areaUrls as $areaUrl) {
            $post = $this->createPost($areaUrl, $area);
            $formattedAreaUrl[] = $post;
        }

        return $formattedAreaUrl;
    }

    /**
     * Auto-extract page URLs
     */
    public function autoExtractUrls()
    {
        $dom = $this->grawler->document();

        $extractor = new \Smarty\LinkExtractor\Extractor($dom, $this->url);

        $results = $extractor->getArticles();

        return array_map(function ($result) {
            return $this->createPost($result);
        }, $results);
    }

    /**
     * Create a new Post instance from url.
     *
     * @return Post post instance
     */
    public function createPost($url,$area = null)
    {
        $urlManipulator = new UrlManipulator();

        $post = new Post();
        $post->setOriginUrl($urlManipulator->setUrl($url)->normalize()->get());

        // Sett the language
        $language = (new Language())->setProvider('editor')->setCode($this->page->getLanguage());
        $post->setLanguage($language);

        $domain = $this->page->getDomain();

        // Set crawler source
        $webCrawlerSource = (new WebCrawlerSource())
            ->setDomainId($domain->getId())
            ->setDomain($domain->getName())
            ->setPageId($this->page->getId())
            ->setPageName($this->page->getName());

        if($area) {
            $location = (new Location())->setName($domain->getLocation())
                ->setLatitude($domain->getLatitude())
                ->setLongitude($domain->getLongitude())
                ->setArea($area);

            $webCrawlerSource
                ->setCategory($area->getCategories()->asArray())
                ->setSubcategory($area->getSubcategories()->asArray())
                ->setArea($area);
        } else {
            $location = (new Location())->setName($domain->getLocation())
                ->setLatitude($domain->getLatitude())
                ->setLongitude($domain->getLongitude());
        }

        $post->setLocation($location);
        $post->setWebCrawlerSource($webCrawlerSource);

        return $post;
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
                'class_name'    => 'Services\Parser\HtmlListPageParser',
                'process_time'  => $this->timer->result(),
            ]);
        }
    }
}
