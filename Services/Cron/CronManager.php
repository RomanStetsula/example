<?php

namespace Crawler\Services\Cron;

use TiBeN\CrontabManager\CrontabAdapter;
use TiBeN\CrontabManager\CrontabJob;
use TiBeN\CrontabManager\CrontabRepository;

class CronManager
{
    /**
     * @var CrontabRepository
     */
    protected $cronRepository;

    /**
     * CronManager constructor.
     */
    public function __construct()
    {
        $this->cronRepository = new CrontabRepository(new CrontabAdapter());
    }

    /**
     * add the app scheduler.
     *
     * @return int
     */
    public function turnOn()
    {
        $this->turnOff();

        $cronJob = $this->makeDefaultJob();

        $this->cronRepository->addJob($cronJob);
        $this->cronRepository->persist();

        return in_array($cronJob, (array) $this->cronRepository->getJobs());
    }

    /**
     * remove all cron jobs.
     */
    public function turnOff()
    {
        $cronJobs = $this->cronRepository->getJobs();

        $defaultJob = $this->makeDefaultJob();

        if (!empty($cronJobs)) {
            foreach ($cronJobs as $cronJob) {
                if ($cronJob == $defaultJob) {
                    $this->cronRepository->removeJob($cronJob);
                    break;
                }
            }
            $this->cronRepository->persist();
        }

        return !in_array($this->makeDefaultJob(), (array) $this->cronRepository->getJobs());
    }

    /**
     * @return CrontabJob
     */
    protected function makeDefaultJob()
    {
        $cronJob = new CrontabJob();
        $cronJob->minutes = '*';
        $cronJob->hours = '*';
        $cronJob->dayOfMonth = '*';
        $cronJob->months = '*';
        $cronJob->dayOfWeek = '*';
        $cronJob->taskCommandLine = 'php '.base_path().'/artisan schedule:run >> /dev/null 2>&1';
        $cronJob->comments = 'running the crawler master cron jobs';

        return $cronJob; // Comments are persisted in the crontab
    }
}
