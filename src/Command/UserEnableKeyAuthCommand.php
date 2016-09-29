<?php

namespace Droid\Plugin\User\Command;

use RuntimeException;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Droid\Lib\Plugin\Command\CheckableTrait;

class UserEnableKeyAuthCommand extends Command
{
    use CheckableTrait;

    const SSH_CLIENT_CONFDIR = '.ssh';
    const SSH_CLIENT_AUTH_KEYFILE = 'authorized_keys';

    public function configure()
    {
        $this
            ->setName('user:enable-key-auth')
            ->setDescription('Append ssh public keys to a user authorized_keys file.')
            ->addArgument(
                'pubkey',
                InputArgument::REQUIRED,
                'One or more lines of ssh public key data.'
            )
            ->addArgument(
                'homedir',
                InputArgument::REQUIRED,
                'Path to the homedir of the user.'
            )
        ;
        $this->configureCheckMode();
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->activateCheckMode($input);

        // sane keys?
        $candidatePubkeys = $this->validateKeys(
            $this->preProcessKeys($input->getArgument('pubkey'))
        );
        if (!$candidatePubkeys) {
            $output->writeLn(
                'I cannot enable auth with the provided pubkeys because they are not well formed.'
            );
            $this->reportChange($output);
            return 1;
        }
        $candidatePubkeyCount = sizeof($candidatePubkeys);

        // sane homedir?
        $homedir = $input->getArgument('homedir');
        if (file_exists($homedir) === false || is_dir($homedir) === false) {
            $output->writeLn(
                sprintf(
                    'I cannot enable auth because the homedir does not exist: "%s".',
                    $homedir
                )
            );
            $this->reportChange($output);
            return 1;
        }
        if (is_readable($homedir) === false) {
            $output->writeLn(
                sprintf(
                    'I cannot enable auth because the homedir is unreadable: "%s".',
                    $homedir
                )
            );
            $this->reportChange($output);
            return 1;
        }

        // test for ~/.ssh/
        $confdir = $homedir . DIRECTORY_SEPARATOR . self::SSH_CLIENT_CONFDIR;
        if (file_exists($confdir) === false || is_dir($confdir) === false) {
            $output->writeLn(
                sprintf(
                    'I cannot enable auth because the config directory does not exist: "%s".',
                    $confdir
                )
            );
            $this->reportChange($output);
            return 1;
        }
        if (is_readable($confdir) === false) {
            $output->writeLn(
                sprintf(
                    'I cannot enable auth because the config directory is unreadable: "%s".',
                    $confdir
                )
            );
            $this->reportChange($output);
            return 1;
        }

        // authorized_keys must be readable, if it exists
        $keysFile = $confdir . DIRECTORY_SEPARATOR . self::SSH_CLIENT_AUTH_KEYFILE;
        if (file_exists($keysFile) && is_readable($keysFile) === false) {
            $output->writeLn(
                sprintf(
                    'I cannot enable auth because the authorized_keys file is unreadable: "%s".',
                    $keysFile
                )
            );
            $this->reportChange($output);
            return 1;
        }

        // get any existing authorized_keys
        $existingKeys = null;
        try {
            $existingKeys = $this->readAuthdKeys($keysFile);
        } catch (RuntimeException $e) {
            $output->writeLn(
                sprintf(
                    'I cannot read existing authorized_keys at "%s": "%s".',
                    $keysFile,
                    $e->getMessage()
                )
            );
            $this->reportChange($output);
            return 1;
        }

        // filter out already authorized_keys
        $keysToEnable = $candidatePubkeys;
        if ($existingKeys) {
            $keysToEnable = $this->filterKeys(
                $candidatePubkeys,
                $existingKeys
            );
            if (sizeof($keysToEnable) == 0) {
                $singular = $candidatePubkeyCount == 1;
                $output->writeLn(
                    sprintf(
                        'I have nothing to do: the %d public key%s supplied %s already enabled.',
                        $candidatePubkeyCount,
                        $singular ? '' : 's',
                        $singular ? 'is' : 'are'
                    )
                );
                $this->reportChange($output);
                return 0;
            } elseif (sizeof($keysToEnable) != $candidatePubkeyCount) {
                $singular = ($candidatePubkeyCount - sizeof($keysToEnable)) == 1;
                $output->writeLn(
                    sprintf(
                        'I will enable just %d of the %d supplied public keys because %d %s already enabled.',
                        sizeof($keysToEnable),
                        $candidatePubkeyCount,
                        $candidatePubkeyCount - sizeof($keysToEnable),
                        $singular ? 'is' : 'are'
                    )
                );
            }
        }

        // write pubkeys
        $this->markChange();
        try {
            $this->writeKeys($keysToEnable, $keysFile);
        } catch (RuntimeException $e) {
            $output->writeLn(
                sprintf(
                    'I cannot write the provided keys to "%s": "%s".',
                    $keysFile,
                    $e->getMessage()
                )
            );
            $this->reportChange($output);
            return 1;
        }

        $output->writeLn(
            sprintf(
                'I %s enabled auth for %d key%s.',
                $this->checkMode() ? 'would have' : 'have successfully',
                sizeof($keysToEnable),
                sizeof($keysToEnable) == 1 ? '' : 's'
            )
        );

        $this->reportChange($output);
        return 0;
    }

    private function preProcessKeys($keyData)
    {
        if (empty($keyData)) {
            return null;
        }
        if (substr($keyData, 0, 5) === 'data:') {
            $keyData = file_get_contents($keyData);
            if ($keyData === false) {
                return null;
            }
        }
        return explode("\n", $keyData);
    }

    private function validateKeys($keys)
    {
        $valid = array();
        foreach ($keys as $key) {
            if (empty($key)) {
                continue;
            }
            $parts = explode(' ', $key);
            if (sizeof($parts) < 3) {
                return null;
            }
            $label = array_shift($parts);
            if (substr($label, 0, 4) !== 'ssh-') {
                return null;
            }
            $valid[] = $key;
        }
        return $valid;
    }

    private function readAuthdKeys($path)
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException(
                sprintf('Failed to read the content of "%s".', $path)
            );
        }
        return $data;
    }

    private function filterKeys($candidateKeys, $existingKeys)
    {
        // hash each existing key material
        if (empty($existingKeys)) {
            return $candidateKeys;
        }
        $hashmap = array();
        foreach (explode("\n", $existingKeys) as $line) {
            if (empty($line)) {
                continue;
            }
            list(, $material, ) = explode(' ', $line);
            $hashmap[hash('sha256', $material)] = true;
        }
        // drop candidates which their key material already exists
        $filtered = array();
        foreach ($candidateKeys as $candidate) {
            list(,$material, ) = explode(' ', $candidate);
            if (array_key_exists(hash('sha256', $material), $hashmap)) {
                continue;
            }
            $filtered[] = $candidate;
        }
        return $filtered;
    }

    private function writeKeys($keys, $path)
    {
        if ($this->checkMode()) {
            return;
        }
        $needsNewline = $this->needsInitialNewline($path);
        $fh = fopen($path, 'a');
        if ($fh == false) {
            throw new RuntimeException(
                sprintf('Failed to open "%s" for appending.', $path)
            );
        }
        if ($needsNewline) {
            fwrite($fh, "\n");
        }
        fwrite($fh, implode("\n", $keys));
        fwrite($fh, "\n");
        fclose($fh);
    }

    /*
     * If the file exists, and definitely doesn't end in a newline.
     */
    private function needsInitialNewline($path)
    {
        $result = false;
        if (! file_exists($path)) {
            return $result;
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return $result;
        }
        if (fseek($fh, -1, SEEK_END) === 0) {
            $lastChar = fgetc($fh);
            if ($lastChar !== "\n" && $lastChar !== "\r") {
                $result = true;
            }
        }
        fclose($fh);
        return $result;
    }
}
