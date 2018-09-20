<?php

namespace Crawler\Jobs\Worker;

use Crawler\Services\Parser\Tools\HistoryTracker;
use Log;
use HttpClient;
use Crawler\SDO\Post;
use Crawler\Jobs\Job;
use Crawler\Jobs\JobDispatcher;
use Crawler\Jobs\Master\Distribute;
use Crawler\Services\Parser\Tools\Timer;
use Crawler\Services\Parser\DataPageParser;
use Crawler\Services\Parser\Tools\DuplicationTracker;
use Smarty\Nodes\Crawler\SmartyPage;
use Smarty\Nodes\Crawler\SmartyDomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ParseData extends Job implements ShouldQueue
{
    use InteractsWithQueue;
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
    protected $page;

    /**
     * The domain of the page.
     *
     * @var SmartyDomain
     */
    private $domain;

    /**
     * The domain post to extract data from.
     *
     * @var Post
     */
    private $post;

    /**
     * @var Timer
     */
    protected $timer;

    /**
     * Item that needs to be recrawled
     * 
     * @var string
     */
    protected $recrawled_item;

    /**
     * @var DuplicationTracker
     */
    public $duplication;

    /**
     * @var
     */
    public $history;

    /**
     * @var bool
     */
    protected $recrawled;

    /**
     * Uncrawled queue name 
     * 
     * @var string
     */
    protected $uncrawled_queue = 'sm:presaver:crawler:queue';

    /**
     * Create a new command instance.
     *
     * ParseData constructor.
     * @param Post $post
     * @param $domain
     * @param $pageId
     */
    public function __construct(Post $post, $domain, $pageId, $recrawled = false)
    {
        $this->timer = new Timer();
        $this->post = $post;
        $this->domain = $domain;
        $this->pageId = $pageId;
        $this->recrawled = $recrawled;
    }

    /**
     * Execute the command.
     */
    public function handle()
    {
        $this->timer->start();

        if($this->attempts() > 1){
            $this->log('Parse Data job was attempted too many times');
            return;
        }

        $this->duplication = new DuplicationTracker($this->post->getOriginUrl());


        // Parse the page content
        $parser = new DataPageParser($this->post, $this->domain, $this->recrawled);
        $post = $parser->parse();

        if(!$post){
            return;
        }

        $this->timer->stop();

        if($this->domain){
            $this->trackErrors($post);
        }

        if ($post['title'] && $post['body']){
            $this->reportSuccess();
        } else {
            $this->reportIssue();
            return;
        }

        // Only dispatch posts that were not crawled yet
        if (!$this->duplication->isCrawled($post['title'], $post['body'])) {
            $job = new Distribute($post);
            JobDispatcher::dispatch($job);
        } else {
            $this->reportDuplicate();
        }

        if($post['title'] || $post['body'])
            $this->duplication->markCrawled($post['title'], $post['body']);
    }

    /**
     * Error tracking logic
     *
     * @param $post
     */
    private function trackErrors($post){

        $url = $this->domain->getName();

        $domainId = $this->domain->getId();

        $type = 'data';

        $this->history = new HistoryTracker();

        $issueHistory = $this->history->getParsingIssue($url, $type);

        if($issueHistory) {
            //update issue record
            $postUrl = $issueHistory->url;
            $pageId = $issueHistory->page_id;
            $number = $issueHistory->number + 1;

            /* if 'true' error will be updated even $post['parsingErrors'] will be
            *  empty (case we want remove issue error from cache) and smarty DB */
            $updateAnyway = false;

            //case when no image parsing error, but exist in cache
            if(isset($issueHistory->error->image) && !isset($post['parsingErrors']->image)){
                $updateAnyway = true;
                if ($number >= env("DOMAIN_VISIT_THRESHOLD")) {
                    //set number to update issue in smarty db
                    $number =  env("DOMAIN_VISIT_THRESHOLD");
                }
            //case when no image error in cache, but exist in post
            } elseif(!isset($issueHistory->error->image) && isset($post['parsingErrors']->image)) {
                // remove it because domain allowed post without images
                unset($post['parsingErrors']->image);
            }

            // update issue record in cache
            if(!empty((array) $post['parsingErrors']) || $updateAnyway){
                $this->history->updateParsingIssue($url, $pageId, $postUrl, $post['parsingErrors'], $number, $type, $domainId);
            }
        //create issue record in cache
        } elseif(!empty((array) $post['parsingErrors']) && $this->pageId){
            $postUrl = $this->post->getOriginUrl();
            $number = 1;
            $this->history->updateParsingIssue($url, $this->pageId, $postUrl, $post['parsingErrors'], $number, $type, $domainId);
        }
    }

    /**
     * Log that the job was successfully done.
     */
    private function reportSuccess()
    {
        $this->log('The domain has data', 1);
    }

    /**
     * Log that the job was not successfully done.
     */
    private function reportIssue()
    {
        $this->log('The domain has no data extractor', 0);
    }

    /**
     * Log that the page was already crawled.
     */
    private function reportDuplicate()
    {
        $this->log('The page has already been crawled', 0);
    }

    /**
     * Log helper function.
     */
    private function log($message, $success = 0)
    {
        if(env('ENABLE_LOGGING')) {
            Log::notice($message, [
                'success'             => $success,
                'domain_id'           => $this->domain ? $this->domain->getId(): 'recrawl',
                'domain_name'         => $this->domain ? $this->domain->getName() : 'recrawl',
                'page_url'            => $this->post->getOriginUrl(),
                'app_name'            => 'Crawler2.0',
                'worker_name'         => 'Jobs\Worker\ParseData', 
                'worker_process_time' => $this->timer->result(),
            ]);
        }
    }
}
