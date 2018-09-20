<?php

namespace Crawler\Jobs\Master;

use Carbon\Carbon;
use Crawler\Jobs\Job;
use Crawler\Events\NewsCrawled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;

/**
 * Distribute purpose is to put parsed post to the redis DB
 */
class Distribute extends Job implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * SmartyPost (serialized as array)
     * @var array
     */
    public $post;

    /**
     * Distribute constructor.
     * @param array $post
     */
    public function __construct($post)
    {
        $this->post = $post;
    }

    /**
     * Execute the job.
     * @return bool|void
     */
    public function handle()
    {
        $queue = Queue::connection('master');
        $redis = Redis::connection('master');

        $encodedPost = json_encode($this->post);

        $redis->rpush('sm:dataNormalization:web', $encodedPost);

        $compact = [
            'title' => $this->post['title'],
            'url' => $this->post['origin_url'],
            'time' => Carbon::now('Asia/Beirut')
        ];

        $queue->pushRaw(json_encode($compact), 'webCrawlerInputCompact');

        $count = $redis->command('llen', ['queues:webCrawlerInputCompact']);
        broadcast(new NewsCrawled($count));
    }
}
