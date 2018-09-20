<?php

namespace Crawler\Services\Supervisord;

use Crawler\Machine;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Supervisor\Configuration\Configuration;
use Supervisor\Configuration\Loader\IniFileLoader;
use Supervisor\Configuration\Section\Group;
use Supervisor\Configuration\Section\Program;
use Supervisor\Configuration\Writer\IniFileWriter;
use Illuminate\Support\Facades\Log;

class ConfigurationManager
{
    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var IniFileWriter
     */
    protected $writer;

    /**
     * default configuration file absolute path.
     *
     * @var string
     */
    protected $defaultConfigFile;

    /**
     * worker configuration file absolute path.
     *
     * @var
     */
    protected $workerConfigFile;

    /**
     * master configuration file absolute path.
     *
     * @var string
     */
    protected $masterConfigFile;

    /**
     * current server ip.
     *
     * @var string
     */
    protected $serverIp;

    /**
     * SmartySupervisor constructor.
     */
    public function __construct()
    {
        $this->defaultConfigFile = 'supervisord.conf';
        $this->masterConfigFile = 'supervisord-master-programs.conf';
        $this->workerConfigFile = 'supervisord-worker-programs.conf';
        $this->serverIp = (new Machine())->ip();
    }

    /**
     * Get the current crawler-supervisord configuration value.
     *
     * @param null $mode
     *
     * @return mixed
     */
    public function config($mode = null)
    {
        if ($mode == 'master')
            $this->prepareIO($this->masterConfigFile);

        elseif ($mode == 'worker')
            $this->prepareIO($this->workerConfigFile);

        else
            $this->prepareIO($this->defaultConfigFile);

        return $this->config;
    }

    /**
     * Update supervisor programs config file with the latest user configs.
     *
     * @return bool
     * @throws \Supervisor\Configuration\Exception\WriterException
     *
     * @codeCoverageIgnore
     */
    public function setAllPrograms()
    {
        if ($this->setMasterPrograms() and $this->setWorkerPrograms())
            return true;

        return false;
    }

    /**
     * Update supervisor worker programs config file with the latest user config.
     *
     * @return bool
     * @throws \Supervisor\Configuration\Exception\WriterException
     */
    public function setWorkerPrograms()
    {
        $this->prepareIO($this->workerConfigFile);
        $this->config->reset();

        $programs = $this->formatQueuePrograms(
            config('crawler.worker-programs')
        );

        $group = new Group(config('crawler.worker-group-name'), [
            'programs' => implode(',', $programs),
        ]);

        $this->config->addSection($group);

        return $this->writer->write($this->config);
    }

    /**
     * Update supervisor master programs config file with the latest user config;.
     *
     * @return bool
     * @throws \Supervisor\Configuration\Exception\WriterException
     */
    public function setMasterPrograms()
    {
        $this->prepareIO($this->masterConfigFile);

        $this->config->reset();

        $programs = $this->formatQueuePrograms(
            config('crawler.master-programs'),
            // we need to disable appending the machine ip while adding master
            // queue programs because we only have one master server
            $appendIp = false
        );

        $clerk_programs = '';
        //setting crawler clerk program
        foreach (config('crawler.clerk-programs') as $key => $program) {
            $clerk = new Program('clerk_' . $key, [
                'process_name'   => '%(program_name)s',
                'directory'      => base_path(),
                'numprocs'       => 1,
                'autostart'      => false,
                'autorestart'    => true,
                'stdout_logfile' => storage_path() . '/logs/clerk_' . $key .'.log',
                'command'        => 'php artisan crawler:clerk '. $program['queue'] . ' ' . $program['timing'],
            ]);
            $this->config->addSection($clerk);
            $clerk_programs .= ',clerk_' . $key;
        }

        $commands = [];
        foreach (config('crawler.master-commands') as $key => $command){
            //setting command
            $program = new Program($key, [
                'process_name'   => '%(program_name)s_%(process_num)02d',
                'directory'      => base_path(),
                'numprocs'       => $command['processNum'],
                'autostart'      => false,
                'autorestart'    => true,
                'stdout_logfile' => storage_path() . '/logs/'.$key.'.log',
                'command'        => $command['command'],
            ]);
            $this->config->addSection($program);
            $commands[] = $key;
        }

        $group = new Group(config('crawler.master-group-name'), [
            'programs' => implode(',', $programs) .','. implode(',', $commands) . $clerk_programs
        ]);

        $this->config->addSection($group);

        return $this->writer->write($this->config);
    }

    /**
     * @param array $programs
     * @param bool $appendIP
     * @return array
     */
    private function formatQueuePrograms($programs = [], $appendIP = true)
    {
        $baseProgramCommand = 'php artisan queue:work --daemon --tries=1 --queue=crawler:';

        $formattedPrograms = [];

        foreach ($programs as $program => $processesNum) {
            $settings = [
                'process_name'   => '%(program_name)s_%(process_num)02d',
                'directory'      => base_path(),
                'numprocs'       => (int)$processesNum,
                'autostart'      => false,
                'autorestart'    => true,
//                'stdout_logfile' => storage_path() . '/logs/' . $program . '.log',
            ];

            $command = $baseProgramCommand . $program;

            if ($appendIP) {
                $command = $command . '-' . $this->serverIp;
            }

            $settings['command'] = $command;

            $section = new Program($program, $settings);

            $this->config->addSection($section);

            $formattedPrograms[] = $program;
        }

        return $formattedPrograms;
    }

    /**
     * Load the current Supervisord configuration and prepare the config writer.
     *
     * @param $configFile
     */
    private function prepareIO($configFile)
    {
        $adapter = new Local(base_path());
        $file = new Filesystem($adapter);

        $loader = new IniFileLoader($file, $configFile);

        $this->config = $loader->load();
        $this->writer = new IniFileWriter($file, $configFile);
    }
}
