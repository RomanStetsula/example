<?php

namespace Crawler\Services\Parser\Tools;

class Timer
{
    protected $start;
    protected $end;

    /**
     * Start the timer
     */
    public function start()
    {
        $this->end = null;
        $this->start = microtime(true);
    }

    /**
     * Stop the timer.
     */
    public function stop()
    {
        $this->end = microtime(true);
    }

    /**
     * Get the result of the timer.
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function result()
    {
        if (!$this->start)
            throw new \Exception('You need to start the timer before asking for result');

        if (!$this->end)
            $this->stop();

        return $this->end - $this->start;
    }
}
