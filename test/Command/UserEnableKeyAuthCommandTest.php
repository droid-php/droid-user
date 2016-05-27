<?php

namespace Droid\Test\Plugin\User\Command;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

use Droid\Plugin\User\Command\UserEnableKeyAuthCommand;

class UserEnableKeyAuthCommandTest extends \PHPUnit_Framework_TestCase
{
    protected $app;
    protected $vfs;
    protected $tester;
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
            ->setMethods(array('setArguments', 'getProcess'))
            ->getMock()
        ;

        // Note that, for testing purposes, this is the root directory and all
        // paths begin 'home', not '/home'.
        $this->vfs = vfsStream::setup('home');

        $command = new UserEnableKeyAuthCommand;

        $this->app = new Application;
        $this->app->add($command);
        $this->tester = new CommandTester($command);
    }

    public function testCommandFailsWhenKeysAreMalformed()
    {
        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'sh-rsa some_key_material and a comment'
        ));

        $this->assertRegExp(
            '/^I cannot enable auth with the provided pubkeys because they are not well formed/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserHomedirIsMissing()
    {
        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertFalse($this->vfs->hasChild('some_username'));
        $this->assertRegExp(
            '/^I cannot enable auth because the homedir does not exist: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserHomedirIsNotADirectory()
    {
        $this->vfs->addChild(vfsStream::newFile('some_username'));

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($this->vfs->hasChild('some_username'));
        $this->assertRegExp(
            '/^I cannot enable auth because the homedir does not exist: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserHomedirIsUnreadable()
    {
        $this->vfs->addChild(vfsStream::newDirectory('some_username', 0100));

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($this->vfs->hasChild('some_username'));
        $this->assertRegExp(
            '/^I cannot enable auth because the homedir is unreadable: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserSshConfigDirIsMissing()
    {
        $this->vfs->addChild(vfsStream::newDirectory('some_username'));

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($this->vfs->hasChild('some_username'));
        $this->assertFalse($this->vfs->getChild('some_username')->hasChild('.ssh'));
        $this->assertRegExp(
            '/^I cannot enable auth because the config directory does not exist: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserSshConfigDirIsNotADirectory()
    {
        vfsStream::create(array('some_username' => array()));
        $this
            ->vfs
            ->getChild('some_username')
            ->addChild(vfsStream::newFile('.ssh'))
        ;

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($this->vfs->getChild('some_username')->hasChild('.ssh'));
        $this->assertRegExp(
            '/^I cannot enable auth because the config directory does not exist: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserSshConfigDirIsUnreadable()
    {
        vfsStream::create(array('some_username' => array()));
        $this
            ->vfs
            ->getChild('some_username')
            ->addChild(vfsStream::newDirectory('.ssh', 0100))
        ;

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($this->vfs->getChild('some_username')->hasChild('.ssh'));
        $this->assertRegExp(
            '/^I cannot enable auth because the config directory is unreadable: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserAuthorizedKeysIsUnreadable()
    {
        vfsStream::create(array('some_username' => array('.ssh' => array())));
        $sshDir = $this->vfs->getChild('some_username')->getChild('.ssh');
        $sshDir->addChild(vfsStream::newFile('authorized_keys', 0100));

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertTrue($sshDir->hasChild('authorized_keys'));
        $this->assertRegExp(
            '/^I cannot enable auth because the authorized_keys file is unreadable: "[^"]*"/',
            $this->tester->getDisplay()
        );
    }

    public function testCommandWillNotAddExistingKeys()
    {
        vfsStream::create(array('some_username' => array('.ssh' => array())));
        $sshDir = $this->vfs->getChild('some_username')->getChild('.ssh');
        vfsStream::newFile('authorized_keys')
            ->at($sshDir)
            ->setContent('ssh-rsa some_key_material and a comment')
        ;

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a different comment'
        ));

        $this->assertSame(
            'ssh-rsa some_key_material and a comment',
            $sshDir->getChild('authorized_keys')->getContent()
        );
        $this->assertRegExp(
            '/^I have nothing to do: the 1 public key supplied is already enabled./',
            $this->tester->getDisplay()
        );
    }

    public function testCommandFailsWhenUserAuthorizedKeysIsUnwritable()
    {
        vfsStream::create(array('some_username' => array('.ssh' => array())));
        $sshDir = $this->vfs->getChild('some_username')->getChild('.ssh');
        $sshDir->addChild(vfsStream::newFile('authorized_keys', 0400));

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_key_material and a comment'
        ));

        $this->assertRegExp(
            '/^I cannot write the provided keys to "[^"]*": "[^"]*"./',
            $this->tester->getDisplay()
        );
    }

    public function testCommandWillAddSingleKey()
    {
        vfsStream::create(array('some_username' => array('.ssh' => array())));
        $sshDir = $this->vfs->getChild('some_username')->getChild('.ssh');
        vfsStream::newFile('authorized_keys')
            ->at($sshDir)
            ->setContent('ssh-rsa some_key_material and a comment')
        ;

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => 'ssh-rsa some_other_key_material and a comment'
        ));

        $this->assertSame(
            implode(
                "\n",
                array(
                    'ssh-rsa some_key_material and a comment',
                    'ssh-rsa some_other_key_material and a comment',
                    '',
                )
            ),
            $sshDir->getChild('authorized_keys')->getContent()
        );
        $this->assertRegExp(
            '/^I have successfully enabled auth for 1 key./',
            $this->tester->getDisplay()
        );
    }

    public function testCommandWillAddMultipleKeys()
    {
        vfsStream::create(array('some_username' => array('.ssh' => array())));
        $sshDir = $this->vfs->getChild('some_username')->getChild('.ssh');
        vfsStream::newFile('authorized_keys')
            ->at($sshDir)
            ->setContent('ssh-rsa some_key_material and a comment')
        ;

        $this->tester->execute(array(
            'command' => $this->app->find('user:enable-key-auth')->getName(),
            'homedir' => vfsStream::url('home/some_username'),
            'pubkey' => implode(
                "\n",
                array(
                    'ssh-rsa some_key_material and a comment',
                    'ssh-rsa some_other_key_material and a comment',
                    'ssh-ed2219 even_more_key_material and a comment',
                )
            )
        ));

        $this->assertSame(
            implode(
                "\n",
                array(
                    'ssh-rsa some_key_material and a comment',
                    'ssh-rsa some_other_key_material and a comment',
                    'ssh-ed2219 even_more_key_material and a comment',
                    '',
                )
            ),
            $sshDir->getChild('authorized_keys')->getContent()
        );
        $this->assertRegExp(
            '/^I will enable just 2 of the 3 supplied public keys because 1 is already enabled./',
            $this->tester->getDisplay()
        );
        $this->assertRegExp(
            '/I have successfully enabled auth for 2 keys./',
            $this->tester->getDisplay()
        );
    }
}
