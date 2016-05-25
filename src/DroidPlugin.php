<?php

namespace Droid\Plugin\User;

use Symfony\Component\Process\ProcessBuilder;

use Droid\Plugin\User\Command\UserCreateCommand;

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
        );
    }
}
