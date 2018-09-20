<?php

namespace Crawler\Services\Supervisord;

use fXmlRpc\Client as RPCClient;
use fXmlRpc\Transport\HttpAdapterTransport;
use GuzzleHttp\Client as GuzzleClient;
use Http\Message\MessageFactory\DiactorosMessageFactory;
use Http\Adapter\Guzzle6\Client;
use Supervisor\Supervisor;
use Supervisor\Connector\XmlRpc;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SmartySupervisor
{
    /**
     * @var ConfigurationManager
     */
    protected $configuration;

    /**
     * @var Supervisor
     */
    protected $supervisor;
    /**
     * @var string
     */
    private $supervisorIp;

    /**
     * SmartySupervisor constructor.
     *
     * @param string $supervisorIp
     */
    public function __construct($supervisorIp = '127.0.0.1')
    {
        $this->supervisorIp = $supervisorIp;
        $this->configuration = new ConfigurationManager();

        $this->prepareSupervisor();
    }

    /**
     * Return the state of the supervisor demon.
     *
     * @return array
     */
    public function state()
    {
        try {
            return $this->supervisor->getState();
        } 
        catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Restart supervisord.
     *
     * @param bool $wait
     * @return bool
     */
    public function restart($wait = false)
    {
        $this->supervisor->restart();

        if($wait)
            sleep(5);

        return true;
    }

    /**
     * Start supervisord.
     *
     * @return bool|void
     *
     * @throws \Exception
     */
    public function start()
    {
        if ($this->state())
            return true;

        $executable = (new ExecutableFinder())->find('supervisord');

        if (!$executable)
            throw new \Exception('Make sure supervisord is installed before trying again.');

        $command = 'sudo '. $executable . ' -c ' . base_path() . '/supervisord.conf';
        $process = new Process($command);
        $process->run();

        if ($process->isSuccessful() and $this->state()) 
            return true;
        else
            return false;
    }

    /**
     * Stop supervisord.
     *
     * @return bool
     */
    public function shutdown()
    {
        // If supervisor is running, shut it down
        if ($this->state()) {
            $shutdown = $this->supervisor->shutdown();
            sleep(5);
        } 
        else
            $shutdown = true;

        return $shutdown;
    }

    /**
     * Get all processes information.
     *
     * @return array
     */
    public function processes()
    {
        return $this->supervisor->getAllProcesses();
    }

    /**
     * Stop all processes/programs.
     *
     * @param bool $wait
     *
     * @return bool
     */
    public function stopAll($wait = true)
    {
        return $this->supervisor->stopAllProcesses($wait);
    }

    /**
     * Start all a programs that belongs to a group.
     *
     * @param $name
     * @param bool $wait
     *
     * @return bool
     */
    public function startGroup($name, $wait = false)
    {
        return $this->supervisor->startProcessGroup($name, $wait);
    }

    /**
     * Stop all a programs that belongs to a group.
     *
     * @param $name
     * @param bool $wait
     *
     * @return bool
     */
    public function stopGroup($name, $wait = false)
    {
        return $this->supervisor->stopProcessGroup($name, $wait);
    }

    /**
     * Get group processes.
     *
     * @param $name
     * @param int $running
     *
     * @return \Illuminate\Support\Collection|static
     */
    public function getGroupProcesses($name, $running = 0)
    {
        $processes = collect($this->supervisor->getAllProcessInfo());
        $processes = $processes->filter(function ($item) use ($name) {
            return $item['group'] == $name;
        });

        if ($running) {
            $processes = $processes->filter(function ($item) {
                return $item['statename'] == 'RUNNING';
            });
        }

        return $processes;
    }

    /**
     * Get group stats.
     * 
     * @param $name
     *
     * @return array
     */
    public function getGroupStats($name)
    {
        $stats = [];

        $processes = $this->getGroupProcesses($name);

        foreach ($processes as $process) {
            $program = strstr($process['name'], '_', true);

            if (!isset($stats[$program])) {
                $stats[$program] = [
                    'name'              => $program,
                    'running_processes' => 0,
                    'stopped_processes' => 0,
                    'total_processes'   => 0,
                ];
            }

            if ($process['statename'] == 'RUNNING')
                ++$stats[$program]['running_processes'];
            else
                ++$stats[$program]['stopped_processes'];

            ++$stats[$program]['total_processes'];
        }

        foreach ($stats as $program) {
            if ($program['running_processes'] == 0)
                $stats[$program['name']]['state'] = 'STOPPED';
            elseif ($program['running_processes'] - $program['total_processes'] == 0)
                $stats[$program['name']]['state'] = 'RUNNING';
            else
                $stats[$program['name']]['state'] = 'PARTIAL';

            $stats[$program['name']] = collect($stats[$program['name']]);
        }

        return collect($stats);
    }

    /**
     * Prepare a suprevisord instance.
     */
    private function prepareSupervisor()
    {
        $guzzle = new GuzzleClient(['auth' => ['smarty', '#$34ERer'],'connect_timeout' => 3.16]);

        $rpc = new RPCClient(
            'http://' . $this->supervisorIp . ':9002/RPC2',
            new HttpAdapterTransport(
                new DiactorosMessageFactory(),
                new Client($guzzle)
            )
        );

        $connector = new XmlRpc($rpc);

        $this->supervisor = new Supervisor($connector);
    }
}
