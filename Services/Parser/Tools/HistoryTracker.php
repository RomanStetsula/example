<?php

namespace Crawler\Services\Parser\Tools;

use Carbon\Carbon;
use Cron\CronExpression;
use Crawler\Server;
use Smarty\Nodes\Crawler\SmartyPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class HistoryTracker
{
    /**
     * Redis Cache connection
     * 
     * @var Redis
     */
    protected $redis_cache;

    /**
     * Cache history prefix.
     *
     * @var string
     */
    protected $history_prefix;

    /**
     * Cache visited prefix.
     *
     * @var string
     */
    protected $visited_prefix;

    /**
     * Cache the issue prefix
     *
     * @var string
     */
    protected $issues_prefix;

    /**
     * Cache the issue queue prefix
     *
     * @var
     */
    protected $issues_queue;

    /**
     * HistoryTracker constructor.
     */
    public function __construct()
    {
        $this->history_prefix = config('cache.prefix').':history:';
        $this->visited_prefix = 'visited:';
        $this->issues_prefix = config('cache.prefix').':issues:';
        $this->issues_queue = 'queues:crawler:issues';

        $this->redis_cache = Redis::connection('cache');
    }

    /**
     * Get the domain history.
     *
     * @param string $domain
     *
     * @return Collection
     */
    public function get(string $domain) : Collection
    {
        $result = json_decode($this->redis_cache->get($this->history_prefix . $this->hash($domain)), true);

        return is_array($result) ? collect($result) : collect([]);
    }

    /**
     * Update the last visit time of a server to a domain.
     *
     * @param string $domainUrl
     * @param Server $server
     * @param int    $delay
     *
     * @return bool
     */
    public function update(string $domainUrl, Server $server, int $delay = 0)
    {
        $history = $this->get($domainUrl);

        $history[$server->ip] = [
            'ip' => $server->ip,
            'last_visit_time' => time() + $delay,
            'url' => $domainUrl,
        ];

        // Set the key
        $key = $this->history_prefix . $this->hash($domainUrl);

        // Set the value
        $value = json_encode($history);

        $this->redis_cache->set($key, $value);

        return $this->redis_cache->expire($key, 86400); // 24 hours
    }

    /**
     * Mark a post as visited by caching its hash value

     * @param $postUrl
     */
    public function markVisited($postUrl)
    {
        // Cache for 5 days
        $expiresAt = Carbon::now()->addDay(5);

        // Set the key
        $key = $this->visited_prefix . $this->hash($postUrl);

        // Set the value
        $value = time() . '-' . $postUrl;

        // Store/Update the URL in cache so it won't be dispatched again
        Cache::put($key, $value, $expiresAt);
    }

    /**
     * Check if a post is visited by checking the cache.
     *
     * @param $postUrl
     * @return mixed
     */
    public function isVisited($postUrl)
    {
        return Cache::get($this->visited_prefix . $this->hash($postUrl));
    }

    /**
     * @param $url
     * @return mixed
     */
    public function getParsingIssue($url, $type) {
        return json_decode($this->redis_cache->get($this->issues_prefix . $this->hash($url . $type)));
    }

    /**
     * Update or create issue in redis of master
     *
     * @param $url
     * @param $pageId
     * @param $postUrl
     * @param $error
     * @param $number
     * @param $type
     * @param $domainId
     */
    public function updateParsingIssue($url, $pageId, $postUrl, $error, $number, $type, $domainId)
    {
        $key = $this->issues_prefix . $this->hash($url . $type);

        $value = json_encode([
            'page_id' => $pageId, 
            'url' => $postUrl, 
            'error' => $error,
            'number' => $number,
            'type' => $type,
            'domain_id' => $domainId
        ], JSON_FORCE_OBJECT);

        $this->redis_cache->set($key, $value);
        $this->redis_cache->expire($key, 86400); // 24 hours

        if(!($number % env("DOMAIN_VISIT_THRESHOLD")))
            Redis::lpush($this->issues_queue, $value);
    }

    /**
     * @param $string
     *
     * @return mixed
     */
    private function hash($string)
    {
        return hash('sha256', $string);
    }
}
