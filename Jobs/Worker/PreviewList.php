<?php

namespace Crawler\Jobs\Worker;

use Crawler\Jobs\Job;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Redis;
use Crawler\Services\Parser\ListPageParser;
use Crawler\Jobs\Master\PreviewRoute;
use Crawler\Jobs\JobDispatcher;
use Illuminate\Support\Facades\Log;

class PreviewList extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * This error will be displayed when page can't be parsed
     */
    const PAGE_ERROR_MESSAGE = 'Error! Page can not be parsed. Page is forbidden or "Section Path" of Area is incorrect.';

    /**
     * Preview List time to live, seconds
     */
    const TTL = 3600;

    /**
     * @var
     */
    public $page;

    /**
     * @var
     */
    public $previewRedis;

    /**
     * @var
     */
    public $extractors_string;

    /**
     * PreviewList constructor.
     * @param $page
     * @param $extractors_string
     */
    public function __construct($page, $extractors_string)
    {
        $this->page = $page;
        $this->extractors_string = $extractors_string;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $pageId = $this->page->getId();

        $data = (new ListPageParser($this->page))->parse();

        $dataset = [];
        if(gettype($data) !== 'array'){
            $dataset['error'] = 'Links cant be extracted from page content of page: '.$this->page->getUrl();
        } else {
            unset($data['error']);

            $data = array_map(function ($post) {
                return $post->toArray();
            }, $data);

            if(empty($data)){
                $dataset['error'] = self::PAGE_ERROR_MESSAGE;
            } else {
                $domain = $this->page->getDomain();
                $dataset['urls'] = $data;
            }
        }

        Redis::connection('preview')->set('listPage:'.$pageId, json_encode($dataset), 'EX', self::TTL);

        if(isset($dataset['urls'])){
            $this->dispatchJobs($domain, $dataset['urls']);
        }
    }

    /**
     * Dispatch preview job in to queue
     * @param $domain
     * @param $data
     */
    private function dispatchJobs($domain, $data)
    {
        $domainName = $domain->getName();

        foreach($data as $post){
            $PreviewRoute = new PreviewRoute($domainName, new PreviewPost($domain, $post['origin_url'], $this->extractors_string));
            JobDispatcher::dispatch($PreviewRoute);
        }
    }
}
