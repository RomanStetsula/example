<?php

namespace Crawler\Jobs\Worker;

use Exception;
use Crawler\Jobs\Job;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Crawler\Services\Parser\DataPageParser;
use Smarty\Nodes\Crawler\SmartyDomain;
use Crawler\SDO\Post;
use Illuminate\Support\Facades\Redis;

/**
 * Class PreviewPost
 * @package Crawler\Jobs\Worker
 */
class PreviewPost extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * time to live cached post data, seconds
     */
    const TTL = 600;

    /**
     * @var
     */
    private $post_url;

    /**
     * @var
     */
    private $domain;

    /**
     * @var Post
     */
    private $post;

    /**
     * @var
     */
    private $previewRedis;

    /**
     * @var
     */
    private $postKey;

    /**
     * PreviewPost constructor.
     * @param SmartyDomain $domain
     * @param $url
     * @param $extractors_string
     */
    public function __construct(SmartyDomain $domain, $url, $extractors_string)
    {
        $this->post_url = urldecode($url);
        $this->domain = $domain;
        $this->post = new Post();
        $this->post->setOriginUrl($url);
        $this->postKey = 'post_preview:' . $extractors_string . ':' . $this->post_url;
        $this->previewRedis = Redis::connection('preview');
    }

    /**
     * Execute the job.
     * @throws \Exception
     */
    public function handle()
    {
        $parsed_post = json_decode($this->previewRedis->get($this->postKey), true);

        //if post not parsed yet or error caused on previous post parsing
        if( !$parsed_post ) {
            $parsed_post = (new DataPageParser($this->post, $this->domain))->parse();
        }

        //update or store parsed post in cache
        $this->previewRedis->set($this->postKey, json_encode($parsed_post), 'EX', self::TTL);
    }

    /**
     * The job failed to process.
     * @param  Exception  $exception
     * @return void
     */
    public function failed(Exception $exception)
    {
        $error['trace'] = $exception->getTraceAsString();
        $error['error'] = 'ERROR: '.$exception->getMessage();

        $this->previewRedis->set($this->postKey, json_encode($error), 'EX', self::TTL);
    }

}
