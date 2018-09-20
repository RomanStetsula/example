<?php

namespace Crawler\Jobs\Worker;

use Crawler\Jobs\Job;
use Crawler\Jobs\JobDispatcher;
use Crawler\Jobs\Master\Route;
use Crawler\Services\Parser\ListPageParser;
use Crawler\Services\Parser\Tools\HistoryTracker;
use Crawler\Services\Parser\Tools\Timer;
use Smarty\Nodes\Crawler\SmartyPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ParseList extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * @var SmartyPage
     */
    private $page;

    /**
     * @var Timer
     */
    protected $timer;

    /**
     * @var HistoryTracker
     */
    protected $history;

    /**
     *  @var string
     */
    protected $pageUrl;

    /**
     * @var object SmartyDomain
     */
    protected $domain;

    /*
     * @var int
     */
    protected $pageId;

    /**
     * Create a new command instance.
     *
     * @param SmartyPage $page
     */
    public function __construct(SmartyPage $page)
    {
        $this->timer = new Timer();
        $this->page = $page;
        $this->pageUrl = $page->getUrl();
        $this->domain = $page->getDomain();
        $this->pageId = $page->getId();
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        if($this->attempts() > 1){
            $this->log('Parse List job was attempted too many times');
            return;
        }

        $this->timer->start();
        $this->history = new HistoryTracker();
        
        // Extract URLs from page
        $dataPages = (new ListPageParser($this->page))->parse();
        
        $this->timer->stop();

        if (isset($dataPages['error']) &&  !empty($dataPages['error'])) {

            $domainId = $this->domain->getId();

            $type = 'list';

            $issueHistory = $this->history->getParsingIssue($this->pageUrl, $type);

            $number = ($issueHistory)? ++$issueHistory->number : 1;

            $this->history->updateParsingIssue($this->pageUrl, $this->pageId, $this->pageUrl, $dataPages['error'], $number, $type, $domainId);

        }

        if(is_array($dataPages)){
            unset($dataPages['error']);
        }

        // Report about success
        if (!empty($dataPages) && is_array($dataPages)){
            $this->reportSuccess();
            $this->dispatchNewPages($dataPages);
        }

    }

    /**
     * Dispatches new pages for parsing.
     *
     * @param array $dataPages pages that needs parsing (list of URLs)
     */
    protected function dispatchNewPages($dataPages)
    {
        foreach ($dataPages as $post) {
            // Only dispatch urls that aren't in the cache
            if (!$this->history->isVisited($post->getOriginUrl()))
                $this->dispatchPage($post);

            $this->history->markVisited($post->getOriginUrl());
        }
    }

    /**
     * Dispatch page for data extraction
     *
     * @param $post
     */
    protected function dispatchPage($post)
    {
        $domainName = $this->domain->getName();

        // Dispatching post url for data extraction
        $job = (new ParseData($post, $this->page->getDomain(), $this->pageId));
        $router = new Route($domainName, $job);

        JobDispatcher::dispatch($router);
    }

    /**
     * Log that the job was successfully done.
     */
    private function reportSuccess()
    {
        $this->log('The page ' . $this->page->getName() . ' with ID: ' . $this->pageId . ' has data', 1);
    }

    /**
     * Log that the job didn't succeed as expected.
     */
    private function reportIssue()
    {
        $this->log('The page ' . $this->page->getName() . ' with ID: ' . $this->pageId . ' has no data', 0);
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
                'success'             => $success,
                'page_id'             => $this->pageId,
                'page_url'            => $this->pageUrl,
                'app_name'            => 'Crawler2.0',
                'worker_name'         => 'Jobs\Worker\ParseList',
                'worker_process_time' => $this->timer->result(),
            ]);
        }
    }
}
