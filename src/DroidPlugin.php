<?php

namespace Droid\Plugin\User;

use Symfony\Component\Process\ProcessBuilder;

use Droid\Plugin\User\Command\UserCreateCommand;
use Droid\Plugin\User\Command\UserEnableKeyAuthCommand;

class DroidPlugin
{
    public function __construct($droid)
    {
        $this->droid = $droid;
    }

    public function getCommands()
    {
        return array(
            new UserCreateCommand(new ProcessBuilder),
            new UserEnableKeyAuthCommand,
        );
    }
}
