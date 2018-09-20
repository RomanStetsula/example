<?php

namespace Crawler\Services\Parser\Tools;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;

class DuplicationTracker
{
    /**
     * URL without parameters.
     *
     * @var string
     */
    public $url;

    /**
     * Redis cache storage prefix for crawled queue.
     *
     * @var string
     */
    protected $crawled_prefix;
    
    /**
     * DuplicationTracker constructor.
     * @param $url
     */
    public function __construct($url)
    {
        $this->url = $this->cleanUrl($url);
        $this->crawled_prefix = 'crawled:';
    }

    /**
     * Check if a post is already crawled by checking the cache.
     *
     * @param $title
     * @param $body
     * @return mixed
     */
    public function isCrawled($title, $body)
    {
        return Cache::get($this->crawled_prefix . $this->hash($title . $body));
    }

    /**
     * Mark a post as visited by caching its hash value
     *
     * @param $title
     * @param $body
     */
    public function markCrawled($title, $body)
    {
        // Cache for 2 days
        $expiresAt= Carbon::now()->addDay(2);
        
        // Store/Update the URL in cache so it won't be dispatched again
        Cache::put($this->crawled_prefix . $this->hash($title . $body), true, $expiresAt);
    }

    /**
     * 
     * @param $string
     * @return mixed
     */
    private function hash($string)
    {
        return hash('sha256', $string);
    }

    /**
     * Get url without request parameters
     * 
     * @param $url
     * @return mixed
     */
    private function cleanUrl($url)
    {
        preg_match('/https?:\/\/([^\/]*)/', $url, $matches);
        return count($matches) > 2 ? $matches[1] : $url;
    }
}