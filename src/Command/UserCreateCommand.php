<?php

namespace Droid\Plugin\User\Command;

use RuntimeException;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;

use Droid\Lib\Plugin\Command\CheckableTrait;

class UserCreateCommand extends Command
{
    use CheckableTrait;

    private $processBuilder;

    public function __construct(ProcessBuilder $builder, $name = null)
    {
        $this->processBuilder = $builder;
        return parent::__construct($name);
    }

    public function configure()
    {
        $this
            ->setName('user:create')
            ->setDescription('Add a Unix user account.')
            ->addArgument(
                'username',
                InputArgument::REQUIRED,
                'Create a user with this user name.'
            )
        ;
        $this->configureCheckMode();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->activateCheckMode($input);

        $username = $input->getArgument('username');
        if ($this->exists($username)) {
            $output->writeLn(
                sprintf(
                    'I will not create user "%s" because one already exists with that name',
                    $username
                )
            );
            $this->reportChange($output);
            return 1;
        }

        $this->markChange();

        try {
            $this->createUser($username);
        } catch (RuntimeException $e) {
            $output->writeLn(
                sprintf(
                    'I cannot create user "%s": %s',
                    $username,
                    $e->getMessage()
                )
            );
            $this->reportChange($output);
            return $e->getCode();
        }

        $output->writeLn(
            sprintf(
                'I %s a new user "%s".',
                $this->checkMode() ? 'would create' : 'have created',
                $username
            )
        );

        $this->reportChange($output);
        return 0;
    }

    private function getProcess($arguments)
    {
        return $this
            ->processBuilder
            ->setArguments($arguments)
            ->getProcess()
        ;
    }

    private function exists($username)
    {
        return 0 === $this
            ->getProcess(array('id', $username))
            ->run()
        ;
    }

    private function createUser($username)
    {
        if ($this->checkMode()) {
            return;
        }
        $p = $this->getProcess(array('adduser', $username));
        if ($p->run()) {
            throw new RuntimeException(
                trim($p->getErrorOutput()),
                $p->getExitCode()
            );
        }
    }
}
