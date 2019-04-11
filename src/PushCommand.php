<?php


namespace Elendev\NexusComposerPush;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PushCommand extends Command
{

    /**
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    protected function configure()
    {
        $this
            ->setName('nexus-push')
            ->setDescription('Initiate a push to a distant Nexus repository')
            ->setDefinition([
                new InputArgument('version', InputArgument::REQUIRED, 'The package version'),
                new InputOption('name', null, InputArgument::OPTIONAL,
                    'Name of the package (if different from the composer.json file)'),
                new InputOption('url', null, InputArgument::OPTIONAL, 'URL to the distant Nexus repository'),
                new InputOption(
                    'username',
                    null,
                    InputArgument::OPTIONAL,
                    'Username to log in the distant Nexus repository'
                ),
                new InputOption('password', null, InputArgument::OPTIONAL,
                    'Password to log in the distant Nexus repository'),
                new InputOption('ignore-dirs', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                    'Directories to ignore when creating the zip')
            ])
            ->setHelp(
                <<<EOT
The <info>nexus-push</info> command uses the archive command to create a ZIP
archive and send it to the configured (or given) nexus repository.
EOT
            );
    }

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|null|void
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->readComposerFile();

        $fileName = tempnam(sys_get_temp_dir(), 'nexus-push') . '.zip';

        $packageName = $this->getPackageName($input);

        $subdirectory = strtolower(preg_replace(
            '/[^a-zA-Z0-9_]|\./',
            '-',
            $packageName . '-' . $input->getArgument('version')
        ));

        $ignoredDirectories = $this->getDirectoriesToIgnore($input);

        try {
            ZipArchiver::archiveDirectory(
                getcwd(),
                $fileName,
                $subdirectory,
                $ignoredDirectories,
                $output
            );

            $url = $this->generateUrl(
                $input->getOption('url'),
                $packageName,
                $input->getArgument('version')
            );

            $output->write(
                'Execute the Nexus Push for the URL ' . $url . '...',
                true
            );

            $this->sendFile(
                $url,
                $fileName,
                $input->getOption('username'),
                $input->getOption('password')
            );

            $output->write('Archive correctly pushed to the Nexus server');
        } finally {
            $output->write(
                'Remove file ' . $fileName,
                true,
                OutputInterface::VERBOSITY_VERY_VERBOSE
            );
            unlink($fileName);
        }
    }

    /**
     * @var array
     */
    protected $composer = [];

    protected function readComposerFile()
    {
        $path = getcwd() . '/composer.json';
        if (!is_file($path)) {
            $this->fatal('Can not found file composer.json');
        }

        $content = file_get_contents($path);
        $data = json_decode($content, JSON_OBJECT_AS_ARRAY);
        if (!is_array($data)) {
            $this->fatal('composer.json content is not valid');
        }

        $this->composer = $data;
    }

    protected function fatal($message)
    {
        $this->output->writeln('<error>' . $message . '</error>');
        exit(1);
    }

    /**
     * @param string $url
     * @param string $name
     * @param string $version
     *
     * @return string URL to the repository
     */
    private function generateUrl($url, $name, $version)
    {
        if (empty($url)) {
            $url = $this->getNexusExtra('url');

            if (empty($url)) {
                throw new InvalidArgumentException('The option --url is required or has to be provided as an extra argument in composer.json');
            }
        }

        if (empty($name)) {
            $name = $this->composer['name'];
        }

        if (empty($version)) {
            throw new InvalidArgumentException('The version argument is required');
        }

        // Remove trailing slash from URL
        $url = preg_replace('{/$}', '', $url);

        return sprintf('%s/packages/upload/%s/%s', $url, $name, $version);
    }

    /**
     * Try to send a file with the given username/password. If the credentials
     * are not set, try to send a simple request without credentials. If the
     * send fail with a 401, try to use the credentials that may be available
     * in an `auth.json` file or in the
     * `extra` section
     *
     * @param string $url      URL to send the file to
     * @param string $filePath path to the file to send
     * @param string|null $username
     * @param string|null $password
     *
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendFile(
        $url,
        $filePath,
        $username = null,
        $password = null
    ) {
        if (!empty($username) && !empty($password)) {
            $this->postFile($url, $filePath, $username, $password);
            return;
        } else {
            $credentials = [];

            if ($this->getNexusExtra('username') !== null && $this->getNexusExtra('password')) {
                $credentials['extra'] = [
                    'username' => $this->getNexusExtra('username'),
                    'password' => $this->getNexusExtra('password'),
                ];
            }


            if (
                preg_match('{^(?:https?)://([^/]+)(?:/.*)?}', $url, $match)
                && isset($this->composer['config']['http-basic'][$match[1]])
            ) {
                $auth = $this->composer['config']['http-basic'][$match[1]];
                $credentials['auth.json'] = [
                    'username' => $auth['username'],
                    'password' => $auth['password'],
                ];
            }

            // In the case anything else works, try to connect without any credentials.
            $credentials['none'] = [];

            foreach ($credentials as $type => $credential) {
                $this->output->write(
                    '[postFile] Trying credentials ' . $type,
                    true,
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );

                $options = [
                    'body' => fopen($filePath, 'r'),
                ];

                if (!empty($credential)) {
                    $options['auth'] = $credential;
                }

                try {
                    if (empty($credential) || empty($credential['username']) || empty($credential['password'])) {
                        $this->output->write(
                            '[postFile] Use no credentials',
                            true,
                            OutputInterface::VERBOSITY_VERY_VERBOSE
                        );
                        $this->postFile($url, $filePath);
                    } else {
                        $this->output->write(
                            '[postFile] Use user ' . $credential['username'],
                            true,
                            OutputInterface::VERBOSITY_VERY_VERBOSE
                        );
                        $this->postFile(
                            $url,
                            $filePath,
                            $credential['username'],
                            $credential['password']
                        );
                    }

                    return;
                } catch (ClientException $e) {
                    if ($e->getResponse()->getStatusCode() === '401') {
                        if ($type === 'none') {
                            $this->output->write(
                                'Unable to push on server (authentication required)',
                                true,
                                OutputInterface::VERBOSITY_VERY_VERBOSE
                            );
                        } else {
                            $this->output->write(
                                'Unable to authenticate on server with credentials ' . $type,
                                true,
                                OutputInterface::VERBOSITY_VERY_VERBOSE
                            );
                        }
                    } else {
                        $this->output->write(
                            '<error>A network error occured while trying to upload to nexus: ' . $e->getMessage() . '</error>>',
                            true,
                            OutputInterface::VERBOSITY_QUIET
                        );
                    }
                }
            }
        }

        throw new \Exception('Impossible to push to remote repository, use -vvv to have more details');
    }

    /**
     * The file has to be uploaded by hand because of composer limitations
     * (impossible to use Guzzle functions.php file in a composer plugin).
     *
     * @param $url
     * @param $file
     * @param $username
     * @param $password
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function postFile($url, $file, $username = null, $password = null)
    {
        $options = [
            'body' => fopen($file, 'r'),
            'debug' => $this->output->isVeryVerbose(),
        ];

        if (!empty($username) && !empty($password)) {
            $options['auth'] = [$username, $password];
        }

        $this->getClient()->request('PUT', $url, $options);
    }

    /**
     * @return \GuzzleHttp\Client|\GuzzleHttp\ClientInterface
     */
    private function getClient()
    {
        if (empty($this->client)) {
            $this->client = new Client();
        }

        return $this->client;
    }

    /**
     * Return the package name based on the given name or the real package name.
     *
     * @param \Symfony\Component\Console\Input\InputInterface|null $input
     *
     * @return string
     */
    private function getPackageName(InputInterface $input = null)
    {
        if ($input && $input->getOption('name')) {
            return $input->getOption('name');
        } else {
            return $this->composer['name'];
        }
    }

    /**
     * Get the Nexus extra values if available
     *
     * @param $parameter
     * @param null $default
     *
     * @return array|string|null
     */
    private function getNexusExtra($parameter, $default = null)
    {
        $extras = $this->composer['extra'];

        if (!empty($extras['nexus-push'][$parameter])) {
            return $extras['nexus-push'][$parameter];
        } else {
            return $default;
        }
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    private function getDirectoriesToIgnore(InputInterface $input)
    {
        $optionalIgnore = $input->getOption('ignore-dirs');
        $composerIgnores = $this->getNexusExtra('ignore-dirs', []);
        $gitAttrIgnores = $this->getGitAttributesExportIgnores();

        $ignore = array_merge($composerIgnores, $optionalIgnore, ['vendor'], $gitAttrIgnores);
        return array_unique($ignore);
    }

    private function getGitAttributesExportIgnores()
    {
        $path = getcwd() . '/.gitattributes';
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        $lines = explode(PHP_EOL, $contents);
        $ignores = [];
        foreach ($lines as $line) {
            if ($line = trim($line)) {
                $diff = strlen($line) - 13;
                if ($diff > 0 && strpos($line, 'export-ignore', $diff) !== false) {
                    $ignores[] = trim(trim(explode(' ', $line)[0]), DIRECTORY_SEPARATOR);
                }
            }
        }

        return $ignores;
    }
}
