<?php

namespace App\Commands;

use Aws\Ec2\Ec2Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class TunnelStartCommand
 * @package App\Commands
 */
class TunnelStartCommand extends Command
{
    /**
     * Set the interval time when polling for server status
     *
     * @var integer
     */
    public const POLLING_INTERVAL_SECONDS = 3;

    /**
     * Set the max number of status polling attempts
     *
     * @var integer
     */
    public const MAX_POLLING_ATTEMPTS = 20;

    /**
     * Max number of times to attempt reconnection
     *
     * @var integer
     */
    private const TUNNEL_ATTEMPTS = 3;

    /**
     * EC2 Client library
     *
     * @var \Aws\Ec2\Ec2Client
     */
    private $ec2Client;

    /**
     * Our EC2 Instance
     *
     * @var array
     */
    private $ec2Instance;

    /**
     * Configure the input provider
     *
     * @var InputInterface
     */
    private $input;

    /**
     * Configure the output provider
     *
     * @var OutputInterface
     */
    private $output;

    /**
     * Number of times the tunnel has attempted to connect
     *
     * @var int
     */
    private $tunnelAttempts;

    /**
     * TunnelStartCommand constructor.
     */
    public function __construct(Ec2Client $ec2Client)
    {
        $this->ec2Client = $ec2Client;

        $this->tunnelAttempts = 0;

        parent::__construct();
    }

    /**
     * Configure the command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('tunnel:start')
            ->setDescription('Start the SSH tunnel.')
            ->setHelp('This command boots the AWS VPC and establishes the SSH tunnel.')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Display debug information'
            )
        ;
    }

    /**
     * Command wrapper. Enables us to quit if any step fails.
     *
     * @param $name
     * @param $arguments
     *
     * @return void
     */
    public function __call($name, $arguments): void
    {
        $command = str_replace('run', '', $name);

        if (method_exists(__CLASS__, $command)) {
            try {
                $this->{$command}($arguments);
            } catch (\Exception $exception) {
                $this->output->writeln("<error>Error: " . $exception->getMessage() . "</error>");
                exit;
            }
        }
    }

    /**
     * Run the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;

        $output->writeln([
            'SSH Tunnel – Start',
            '==================',
            '',
        ]);

        $output->writeln('Starting SSH tunnel setup…');
        $output->writeln('Starting "' . getenv('TUNNEL_NAME') . '" EC2 instance');
        $this->runSetEC2Instance();
        $this->runStartInstance();

        $output->writeln('Checking server state…');
        $this->runPollStateUntilRunning();

        $output->writeln('Creating tunnel…');
        $this->runCreateSSHTunnel();

        $output->writeln('Launching browser…');
        $this->runLaunchBrowserWithProxy();

        $output->writeln('<info>All done! Enjoy.</info>');
    }

    /**
     * Fetch the EC2 Instance by name
     *
     * @return array|null
     */
    private function fetchEC2Instance(): ?array
    {
        $instances = $this->ec2Client->describeInstances([
            'Filters' => [
                [
                    'Name' => 'tag:Name',
                    'Values' => [getenv('TUNNEL_NAME')],
                ],
            ],
        ]);

        return data_get($instances, 'Reservations.0.Instances.0') ?? null;
    }

    /**
     * Set the EC2 instance
     *
     * @return void
     * @throws \Exception
     */
    private function setEC2Instance(): void
    {
        if ($this->fetchEC2Instance() === null) {
            throw new \Exception('Instance not found');
        }

        $this->ec2Instance = $this->fetchEC2Instance();
    }

    /**
     * Start the EC2 instance
     *
     * @return void
     * @throws \Exception
     */
    private function startInstance(): void
    {
        $startInstance = $this->ec2Client->startInstances([
            'InstanceIds' => [$this->ec2Instance['InstanceId']],
        ]);

        if (data_get($startInstance, 'StartingInstances') === null) {
            throw new \Exception('Unable to start instance');
        }
    }

    /**
     * Return the retrieved server state
     *
     * @return string
     */
    private function checkEC2State(): string
    {
        return data_get($this->fetchEC2Instance(), 'State.Name');
    }

    /**
     * Poll AWS for instance state until it's running
     *
     * @return void
     */
    private function pollStateUntilRunning(): void
    {
        $i = 0;
        do {
            $status = $this->checkEC2State();
            $this->output->writeln('Status: ' . $status);
            sleep(static::POLLING_INTERVAL_SECONDS);
            $i++;
        } while ($status != 'running' && $i <= static::MAX_POLLING_ATTEMPTS);
    }

    /**
     * Launch a proxied web browser
     *
     * @return void
     */
    private function launchBrowserWithProxy(): void
    {
        $command = sprintf(
            '/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome ' .
            '--user-data-dir="~/chrome-with-proxy" ' .
            '--proxy-server="socks5://localhost:%d" ' .
            '%s ' .
            "> /dev/null 2>&1 &", // suppress output
            getenv('TUNNEL_PORT'),
            getenv('TUNNEL_DEFAULT_URL')
        );

        $process = new Process($command);
        $process->mustRun();
    }

    /**
     * Create the SSH tunnel to the server
     *
     * @return void
     */
    private function createSSHTunnel(): void
    {
        $command = sprintf(
            //"ssh -D %d -o ExitOnForwardFailure=yes -o StrictHostKeyChecking=no -fNq %s@%s -i %s",
            'ssh -D %d -N -o "UserKnownHostsFile=/dev/null" -o "StrictHostKeyChecking=no" %s@%s -i %s ' .
            '%s ',
            getenv('TUNNEL_PORT'),
            getenv('SSH_USER'),
            $this->ec2Instance['PublicIpAddress'],
            getenv('SSH_KEY'),
            $this->input->getOption('debug') ? '-vv &' : '> /dev/null 2>&1'
        );

        $clearExistingTunnelsCommand = sprintf("lsof -ti:%d | xargs kill -9", getenv('TUNNEL_PORT'));

        (new Process($clearExistingTunnelsCommand))->run();
        (new Process($command))->start();

        $this->verifyTunnel();
    }

    /**
     * Check if the tunnel was set up, and if not, retry
     *
     * @return void
     */
    private function verifyTunnel(): void
    {
        if ($this->isTunnelEstablished() === false) {
            $this->retryTunnel();
        }
    }

    /**
     * Re-run the tunnel connection process
     *
     * @return void
     * @throws \Exception
     */
    private function retryTunnel(): void
    {
        if ($this->tunnelAttempts > self::TUNNEL_ATTEMPTS) {
            throw new \Exception('Tunnel could not be established');
        }

        $this->runCreateSSHTunnel();
    }

    /**
     * Return whether the tunnel has been created
     *
     * @return bool
     */
    private function isTunnelEstablished(): bool
    {
        $command = sprintf('nc -z localhost %d || echo 0', getenv('TUNNEL_PORT'));

        $process = new Process($command);
        $process->run();

        return trim($process->getOutput()) !== '0';
    }

}