<?php

namespace Crawler\Jobs\Master;

use Log;
use Crawler\Server;
use Crawler\Jobs\Job;
use Crawler\Jobs\JobDispatcher;
use Crawler\Jobs\Worker\ParseData;
use Crawler\Jobs\Worker\ParseList;
use Crawler\Repositories\ServerRepository;
use Crawler\Services\Parser\Tools\HistoryTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class Route extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * @var HistoryTracker
     */
    protected $history;

    /**
     * @var Job
     */
    protected $parsingJob;

    /**
     * @var string
     */
    protected $domainName;

    /**
     * The parsing job that will be dispatched.
     *
     * @var Job
     */
    protected $jobProgramMap = [
        ParseList::class => 'parse-list',
        ParseData::class => 'parse-data',
    ];

    /**
     * @var
     */
    protected $serversRedis;

    /**
     * Cached server time to live, seconds
     */
    const TTL = 600;

    /**
     * Route constructor.
     * @param $domainName
     * @param Job $parsingJob
     */
    public function __construct($domainName, ShouldQueue $parsingJob)
    {
        $this->parsingJob = $parsingJob;
        $this->domainName = $domainName;
        $this->serversRedis = Redis::connection('servers');
    }

    /**
     * Execute the job.
     *
     * @param ServerRepository $server
     * @param HistoryTracker $tracker
     *
     * @return bool|void
     */
    public function handle(ServerRepository $server, HistoryTracker $tracker)
    {
        // Get the domain history
        $this->history = $tracker->get($this->domainName);

        $workers = $this->getWorkers($server);

        // Get the program name from the job class name
        $program = $this->jobProgramMap[get_class($this->parsingJob)] ?? false;

        // Return if no worker or program found
        if ($workers->isEmpty() or !$program) {
            exit;
        }

        $worker = $this->matchBestWorker($workers, $program);

        if (!$worker)
            $worker = $workers->first();

        // We don't need to delay the parse-list job because we already have a frequency.
        if ($program !== 'parse-data')
            $delay = 0;
        else
            $delay = $this->calculateDelay($worker->ip);

        JobDispatcher::dispatch($this->parsingJob, $worker, $delay);

        $tracker->update($this->domainName, $worker, $delay);
    }

    /**
     * Find the worker with the least amount of jobs and a safe threshold.
     *
     * @param $workers
     * @param $program
     *
     * @return Server
     */
    protected function matchBestWorker($workers, $program)
    {
        $worker = null;
        $serverJobs = [];

        // In this loop we are trying to find a worker that can execute the job
        // without a delay with the least amount of jobs
        foreach ($workers as $key => $server) {
            $serverJobCount = $server->jobs($program);

            $serverJobs[] = ['key' => $key, 'ip' => $server->ip, 'count' => $serverJobCount];

            if ($this->isSafeToParseNow($server->ip)) {
                if ($worker and $serverJobCount > $worker->jobs($program))
                    continue;

                $worker = $server;
            }
        }

        // If we couldn't find any worker that can execute the job directly find the one with the least amount of jobs
        if (!$worker) {
            $serverWithLeastJobs = collect($serverJobs)->sortBy('count')->first();
            $worker = $workers[$serverWithLeastJobs['key']];
        }

        return $worker;
    }

    /**
     * Check if this worker can visit this domain without exceeding the crawling threshold.
     *
     * @param $workerIp
     *
     * @return bool
     */
    protected function isSafeToParseNow($workerIp)
    {
        return $this->calculateDelay($workerIp) == 0;
    }

    /**
     * Get the unix time of the last visit that the worker has done or will do
     * from the current domain history.
     *
     * @param $workerIp
     *
     * @return int
     */
    protected function getLastVisitTime($workerIp)
    {
        if (isset($this->history[$workerIp]))
            return $this->history[$workerIp]['last_visit_time'];

        return 0;
    }

    /**
     * Calculate the delay that should be assigned for the job based on the last visit time
     * and the a minimum threshold.
     *
     * @param $workerIp
     *
     * @return int
     */
    protected function calculateDelay($workerIp)
    {
        $lastVisitTime = $this->getLastVisitTime($workerIp);
        $currentTime = time();
        $threshold = config('crawler.domain-visit-threshold');

        if (!$lastVisitTime)
            return 0;

        // If the server want visit this domain in the future
        // check if the time difference is above threshold
        if ($currentTime > $lastVisitTime) {
            if ($currentTime - $lastVisitTime > $threshold){
                $delay = 0;
            } else {
                $delay = $threshold;
            }
        } else {
            $delay = $lastVisitTime - $currentTime + $threshold;
        }

        return $delay;
    }

    /**
     * Get workers info from cache or get from DB and cache them
     * @param $server
     * @return array|\Illuminate\Support\Collection
     */
    protected function getWorkers($server){
        $keys = $this->serversRedis->keys('*');

        $workers = [];
        foreach ($keys as $key){
            //get workers from redis
            $workers[] = unserialize($this->serversRedis->get($key));
        }

        $workers = collect($workers);

        if($workers->isEmpty()){
            //get workers from db
            $workers = $server->workers(true);

            //cache workers in redis
            foreach ($workers as $server){
                $this->serversRedis->set('server:'.$server->ip, serialize($server), 'EX', self::TTL);
            }
        }

        return $workers;
    }

    /**
     * Log helper function.
     */
    protected function log($message, $success = 0, $domain = "")
    {
        if(env('ENABLE_LOGGING')) {
            Log::notice($message, [
                'success'             => $success,
                'domain'              => $domain,
                'app_name'            => 'Crawler2.0',
                'process'             => 'Jobs\Master\Route'
            ]);
        }
    }
}
