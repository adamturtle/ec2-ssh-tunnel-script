<?php

namespace App\Commands;

use Aws\Ec2\Ec2Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TunnelStopCommand
 * @package App\Commands
 */
class TunnelStopCommand extends Command
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
     * Configure the output provider
     *
     * @var OutputInterface
     */
    private $output;

    /**
     * TunnelStartCommand constructor.
     */
    public function __construct(Ec2Client $ec2Client)
    {
        $this->ec2Client = $ec2Client;

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
            ->setName('tunnel:stop')
            ->setDescription('Stop the SSH tunnel.')
            ->setHelp('This command shuts down AWS VPC and kills the SSH tunnel.')
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
        $this->output = $output;

        $output->writeln([
            'SSH Tunnel – Stop',
            '==================',
            '',
        ]);

        $output->writeln('Starting SSH tunnel teardown…');

        $output->writeln('Stopping "' . getenv('TUNNEL_NAME') . '" EC2 instance');
        $this->runSetEC2Instance();
        $this->runStopInstance();

        $output->writeln('Checking server state…');
        $this->runPollStateUntilStopped();

        $output->writeln('<info>Server stopped, tunnel destroyed!</info>');
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
     * Stop the EC2 instance
     *
     * @return void
     * @throws \Exception
     */
    private function stopInstance(): void
    {
        $stopInstance = $this->ec2Client->stopInstances([
            'InstanceIds' => [$this->ec2Instance['InstanceId']],
        ]);

        if (data_get($stopInstance, 'StoppingInstances') === null) {
            throw new \Exception('Unable to stop instance');
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
     * Poll AWS for instance state until it's stopped
     *
     * @return void
     */
    private function pollStateUntilStopped(): void
    {
        $i = 0;
        do {
            $status = $this->checkEC2State();
            $this->output->writeln('Status: ' . $status);
            sleep(static::POLLING_INTERVAL_SECONDS);
            $i++;
        } while ($status != 'stopped' && $i <= static::MAX_POLLING_ATTEMPTS);
    }
}