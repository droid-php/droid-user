<?php

namespace Droid\Test\Plugin\User\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

use Droid\Plugin\User\Command\UserCreateCommand;

class UserCreateCommandTest extends \PHPUnit_Framework_TestCase
{
    protected $app;
    protected $process;
    protected $processBuilder;

    protected function setUp()
    {
        $this->process = $this
            ->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->setMethods(array('run', 'getErrorOutput', 'getExitCode'))
            ->getMock()
        ;
        $this->processBuilder = $this
            ->getMockBuilder(ProcessBuilder::class)
            ->setMethods(array('setArguments', 'setTimeout', 'getProcess'))
            ->getMock()
        ;

        $command = new UserCreateCommand($this->processBuilder);

        $this->app = new Application;
        $this->app->add($command);
    }

    public function testCommandWillNotCreateUserIfExists()
    {
        $command = $this->app->find('user:create');

        // simulate user exists
        $this->processBuilder
            ->expects($this->once())
            ->method('setArguments')
            ->with(array('id', 'some_username'))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->once())
            ->method('setTimeout')
            ->with($this->equalTo(0.0))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->once())
            ->method('getProcess')
            ->willReturn($this->process)
        ;
        $this->process
            ->expects($this->once())
            ->method('run')
            ->willReturn(0)
        ;

        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            'username' => 'some_username'
        ));

        $this->assertRegExp(
            '/^I will not create user "some_username" because one already exists with that name/',
            $commandTester->getDisplay()
        );
    }

    public function testCommandFailsToCreateUser()
    {
        $command = $this->app->find('user:create');

        // simulate user not exists and failed adduser
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('setArguments')
            ->withConsecutive(
                array(array('id', 'some_username')),
                array(array('sudo', 'adduser', 'some_username'))
            )
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('setTimeout')
            ->with($this->equalTo(0.0))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('getProcess')
            ->willReturn($this->process)
        ;
        $this->process
            ->expects($this->exactly(2))
            ->method('run')
            ->willReturnOnConsecutiveCalls(1, 1)
        ;
        $this->process
            ->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('because bad things happened')
        ;
        $this->process
            ->expects($this->once())
            ->method('getExitCode')
            ->willReturn(1)
        ;

        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            'username' => 'some_username'
        ));

        $this->assertRegExp(
            '/^I cannot create user "some_username": because bad things happened/',
            $commandTester->getDisplay()
        );
    }

    public function testCommandWillNotCreateUserInCheckMode()
    {
        $command = $this->app->find('user:create');

        // simulate user not exists and successful adduser
        $this->processBuilder
            ->expects($this->once())
            ->method('setArguments')
            ->with(array('id', 'some_username'))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->once())
            ->method('setTimeout')
            ->with($this->equalTo(0.0))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->once())
            ->method('getProcess')
            ->willReturn($this->process)
        ;
        $this->process
            ->expects($this->once())
            ->method('run')
            ->willReturn(1)
        ;

        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            'username' => 'some_username',
            '--check' => true,
        ));

        $this->assertRegExp(
            '/^I would create a new user "some_username"/',
            $commandTester->getDisplay()
        );

        $this->assertRegExp(
            '/\[DROID\-RESULT\].*"changed":true/',
            $commandTester->getDisplay()
        );
    }

    public function testCommandWillCreateUser()
    {
        $command = $this->app->find('user:create');

        // simulate user not exists and successful adduser
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('setArguments')
            ->withConsecutive(
                array(array('id', 'some_username')),
                array(array('sudo', 'adduser', 'some_username'))
            )
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('setTimeout')
            ->with($this->equalTo(0.0))
            ->willReturnSelf()
        ;
        $this->processBuilder
            ->expects($this->exactly(2))
            ->method('getProcess')
            ->willReturn($this->process)
        ;
        $this->process
            ->expects($this->exactly(2))
            ->method('run')
            ->willReturnOnConsecutiveCalls(1, 0)
        ;

        $commandTester = new CommandTester($command);
        $commandTester->execute(array(
            'command' => $command->getName(),
            'username' => 'some_username'
        ));

        $this->assertRegExp(
            '/^I have created a new user "some_username"/',
            $commandTester->getDisplay()
        );
    }
}
