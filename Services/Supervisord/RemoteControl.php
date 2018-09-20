<?php

namespace Crawler\Services\Supervisord;

use Crawler\Server;
use Crawler\Repositories\ServerRepository;

class RemoteControl
{
    /**
     * @var ServerRepository
     */
    protected $serverRepo;

    /**
     * @var Server
     */
    private $server;

    public function __construct(Server $server)
    {
        $this->serverRepo = new ServerRepository($server);
        $this->server = $server;
    }

    /**
     * Start the processes of the (remote) server
     */
    public function start()
    {
        $this->serverRepo->setBusy($this->server, $busy = true);

        $group = $this->server->type . '-programs';
        $supervisor = new SmartySupervisor($this->server->ip);

        if($supervisor->state()) {
            $supervisor->startGroup($group, $wait = true);
            $stats[$this->server->type] = $supervisor->getGroupStats($group);
            $this->serverRepo->setProcesses($this->server, $stats);
        }

        $this->serverRepo->setBusy($this->server, $busy = false);
    }

    /**
     * Stop the processes of a (remote) server
     */
    public function stop()
    {
        $this->serverRepo->setBusy($this->server, $busy = true);

        $group = $this->server->type . '-programs';
        $supervisor = new SmartySupervisor($this->server->ip);

        if($supervisor->state()) {
            $supervisor->stopGroup($group, $wait = true);
            $stats[$this->server->type] = $supervisor->getGroupStats($group);
            $this->serverRepo->setProcesses($this->server, $stats);
        }

        $this->serverRepo->setBusy($this->server, $busy = false);
    }
}