<?php

namespace Crawler\Jobs\Common;

use Crawler\Jobs\Job;
use Crawler\Repositories\ServerRepository;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class Health extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @param ServerRepository $server
     */
    public function handle(ServerRepository $server)
    {
        $server->health();
    }
}
