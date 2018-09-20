<?php

namespace Crawler\Jobs;

use Crawler\Server;
use Crawler\Jobs\Common\Health;
use Crawler\Jobs\Worker\ParseList;
use Crawler\Jobs\Worker\ParseData;
use Crawler\Jobs\Master\Route;
use Crawler\Jobs\Master\Distribute;
use Crawler\Jobs\Worker\PreviewList;
use Crawler\Jobs\Worker\PreviewPost;
use Crawler\Jobs\Master\PreviewRoute;

class JobDispatcher
{
    protected $jobQueueMap = [
        Health::class       => 'commands',
        ParseList::class    => 'parse-list',
        ParseData::class    => 'parse-data',
        Route::class        => 'route',
        Distribute::class   => 'distribute',
        PreviewList::class  => 'preview-list',
        PreviewPost::class  => 'preview-post',
        PreviewRoute::class => 'preview-route'
    ];

    /**
     * Static function to dispatch a job.
     *
     * @param $job
     * @param Server|null $server
     * @param int $delay
     *
     * @return mixed
     */
    public static function dispatch($job, Server $server = null, $delay = 0)
    {
        $self = new self();

        return $self->doDispatch($job, $server, $delay);
    }

    /**
     * Regular function to dispatch a job.
     *
     * @param $job
     * @param Server|null $server
     * @param int $delay
     * @return mixed
     */
    public function doDispatch($job, Server $server = null, $delay = 0)
    {
        $jobName = get_class($job);
        $queueName = $this->jobQueueMap[$jobName] ?? null;

        if($queueName){
            // Delay the job if required
            $delay && $job->delay($delay);

            $queue = 'crawler:' . $queueName;

            if ($server) {
                // If it's the master server don't append the IP
                $queue = $server->is_master ? $queue : $queue . '-' . $server->ip;
            }

            $job->onQueue($queue);

            return dispatch($job);
        }
    }
}
