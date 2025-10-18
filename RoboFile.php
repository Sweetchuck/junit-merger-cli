<?php

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\Attributes\Argument;
use Consolidation\AnnotatedCommand\Attributes\Command;
use Consolidation\AnnotatedCommand\Attributes\Help;
use Consolidation\AnnotatedCommand\Attributes\Hook;
use Consolidation\AnnotatedCommand\Attributes\Option;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use League\Container\Container as LeagueContainer;
use NuvoleWeb\Robo\Task\Config\loadTasks as ConfigLoader;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Collection\CollectionBuilder;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Contract\TaskInterface;
use Robo\State\Data as RoboStateData;
use Robo\Tasks;
use Sweetchuck\JunitMergerCli\Tests\Attributes\InitLintReporters;
use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\Robo\Composer\ComposerTaskLoader;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
use Sweetchuck\Robo\Phpstan\PhpstanTaskLoader;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class RoboFile extends Tasks implements LoggerAwareInterface, ConfigAwareInterface
{
    use LoggerAwareTrait;
    use ConfigLoader;
    use ConfigAwareTrait;
    use ComposerTaskLoader;
    use GitTaskLoader;
    use PhpcsTaskLoader;
    use PhpstanTaskLoader;

    protected ?RoboStateData $mainState = null;

    /**
     * @var array<string, mixed>
     */
    protected array $composerInfo = [];

    /**
     * @var string[]
     */
    protected array $testSuiteNames = [];

    protected string $packageVendor = '';

    protected string $packageName = '';

    protected string $binDir = 'vendor/bin';

    protected string $gitHook = '';

    protected string $envVarNamePrefix = '';

    /**
     * Allowed values: local, dev, ci, prod.
     */
    protected string $environmentType = '';

    /**
     * Allowed values: local, jenkins, travis, circleci.
     */
    protected string $environmentName = '';

    protected Filesystem $fs;

    public function __construct()
    {
        putenv('COMPOSER_DISABLE_XDEBUG_WARN=1');
        $this->fs = new Filesystem();
        $this
            ->initComposerInfo()
            ->initEnvVarNamePrefix()
            ->initEnvironmentTypeAndName();
    }

    #[Hook(
        type: HookManager::PRE_COMMAND_HOOK,
        selector: InitLintReporters::SELECTOR,
    )]
    public function onHookPreCommandInitLintReporters(): void
    {
        $lintServices = BaseReporter::getServices();
        $container = $this->getContainer();
        if (!($container instanceof LeagueContainer)) {
            return;
        }

        foreach ($lintServices as $name => $class) {
            if ($container->has($name)) {
                continue;
            }

            $container
                ->add($name, $class)
                ->setShared(false);
        }
    }

    #[Command(name: 'githook:pre-commit')]
    #[Help(
        description: 'Git "pre-commit" hook callback.',
        hidden: true,
    )]
    #[InitLintReporters]
    public function gitHookPreCommit(): CollectionBuilder
    {
        $this->gitHook = 'pre-commit';

        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskTestRunSuites());
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Command(name: 'config')]
    #[Help(
        description: 'Prints the current configuration.',
        hidden: true,
    )]
    #[Option(
        name: 'format',
        description: 'The output format.',
    )]
    public function cmdConfigExecute(
        array $options = [
            'format' => 'yaml',
        ],
    ): CommandResult {
        return CommandResult::data($this->getConfig()->export());
    }

    #[Hook(
        type: HookManager::ARGUMENT_VALIDATOR,
        target: 'test',
    )]
    public function cmdTestValidate(CommandData $commandData): void
    {
        $args = $commandData->arguments();
        $this->validateArgTestSuiteNames($args['suiteNames']);
    }

    /**
     * @param array<string> $suiteNames
     */
    #[Command(name: 'test')]
    #[Help(
        description: 'Runs the Robo unit tests.',
    )]
    #[Argument(
        name: 'suiteNames',
        description: 'The test suite names to run.',
    )]
    public function cmdTestExecute(
        array $suiteNames,
    ): TaskInterface {
        return $this->getTaskTestRunSuites($suiteNames);
    }

    #[Command(name: 'lint')]
    #[Help(
        description: 'Runs the code style checkers.',
    )]
    #[InitLintReporters]
    public function cmdLintExecute(): TaskInterface
    {
        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskPhpstanAnalyze());
    }

    #[Command(name: 'lint:phpcs')]
    #[Help(
        description: 'Runs phpcs.',
    )]
    #[InitLintReporters]
    public function cmdLintPhpcsExecute(): TaskInterface
    {
        return $this->getTaskPhpcsLint();
    }

    #[Command(name: 'lint:phpstan')]
    #[Help(
        description: 'Runs phpstan analyze.',
    )]
    #[InitLintReporters]
    public function cmdLintPhpstanExecute(): TaskInterface
    {
        return $this->getTaskPhpstanAnalyze();
    }

    protected function errorOutput(): ?OutputInterface
    {
        $output = $this->output();

        return ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;
    }

    protected function initEnvVarNamePrefix(): static
    {
        $this->envVarNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    protected function initEnvironmentTypeAndName(): static
    {
        $this->environmentType = (string) getenv($this->getEnvVarName('environment_type'));
        $this->environmentName = (string) getenv($this->getEnvVarName('environment_name'));

        if (!$this->environmentType) {
            if (getenv('CI') === 'true') {
                // Travis, GitLab and CircleCI.
                $this->environmentType = 'ci';
            } elseif (getenv('JENKINS_HOME')) {
                $this->environmentType = 'ci';
                if (!$this->environmentName) {
                    $this->environmentName = 'jenkins';
                }
            }
        }

        if (!$this->environmentName && $this->environmentType === 'ci') {
            if (getenv('GITLAB_CI') === 'true') {
                $this->environmentName = 'gitlab';
            } elseif (getenv('TRAVIS') === 'true') {
                $this->environmentName = 'travis';
            } elseif (getenv('CIRCLECI') === 'true') {
                $this->environmentName = 'circleci';
            }
        }

        if (!$this->environmentType) {
            $this->environmentType = 'dev';
        }

        if (!$this->environmentName) {
            $this->environmentName = 'local';
        }

        return $this;
    }

    protected function getEnvVarName(string $name): string
    {
        return "{$this->envVarNamePrefix}_" . strtoupper($name);
    }

    protected function initComposerInfo(): static
    {
        $composerFileName = getenv('COMPOSER') ?: 'composer.json';
        if ($this->composerInfo || !is_readable($composerFileName)) {
            return $this;
        }

        $this->composerInfo = json_decode(file_get_contents($composerFileName) ?: '{}', true);
        [$this->packageVendor, $this->packageName] = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    /**
     * @param array<string> $suiteNames
     */
    protected function getTaskTestRunSuites(array $suiteNames = []): TaskInterface
    {
        if (!$suiteNames) {
            $suiteNames = ['all'];
        }

        /** @var array<php-executable> $phpExecutables */
        $phpExecutables = array_filter(
            (array) $this->getConfig()->get('php.executables'),
            static fn(array $php): bool => !empty($php['enabled']),
        );

        $cb = $this->collectionBuilder();
        foreach ($suiteNames as $suiteName) {
            foreach ($phpExecutables as $phpExecutable) {
                $cb->addTask($this->getTaskTestRunSuite($suiteName, $phpExecutable));
            }
        }

        return $cb;
    }

    /**
     * @param php-executable $php
     */
    protected function getTaskTestRunSuite(string $suite, array $php): TaskInterface
    {
        $command = $php['command'];
        $command[] = "{$this->binDir}/phpunit";
        $command[] = '--colors=always';

        $cb = $this->collectionBuilder();
        if ($suite !== 'all') {
            $command[] = "--testsuite=$suite";

            // Human.
            $command[] = "--testdox-html=./reports/human/$suite/result/testdox.html";
            $command[] = "--testdox-text=./reports/human/$suite/result/testdox.txt";
            $command[] = "--coverage-html=./reports/human/$suite/coverage/html/";

            // Machine.
            $command[] = "--log-junit=./reports/machine/$suite/result/junit.xml";
            $command[] = "--coverage-xml=./reports/machine/$suite/coverage/xml/";
            $command[] = "--coverage-clover=./reports/machine/$suite/coverage/clover.xml";
            $command[] = "--coverage-php=./reports/machine/$suite/coverage/php.php";
        }

        return $cb
            ->addCode(function () use ($command, $php) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'Test',
                        '{command}' => implode(' ', $command),
                    ]
                ));

                $process = new Process(
                    $command,
                    null,
                    $php['envVars'] ?? null,
                    null,
                    null,
                );

                return $process->run(function ($type, $data) {
                    switch ($type) {
                        case Process::OUT:
                            $this->output()->write($data);
                            break;

                        case Process::ERR:
                            $this->errorOutput()->write($data);
                            break;
                    }
                });
            });
    }

    protected function getTaskPhpcsLint(): CollectionBuilder
    {
        $options = [
            'failOn' => 'warning',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
        ];

        if ($this->environmentType === 'ci' && $this->environmentName === 'jenkins') {
            $options['failOn'] = 'never';
            $options['lintReporters']['lintCheckstyleReporter'] = $this
                ->getContainer()
                ->get('lintCheckstyleReporter')
                ->setDestination('reports/machine/checkstyle/phpcs.psr2.xml');
        }

        if ($this->gitHook === 'pre-commit') {
            return $this
                ->collectionBuilder()
                ->addTask($this
                    ->taskPhpcsParseXml()
                    ->setAssetNamePrefix('phpcsXml.'))
                ->addTask($this
                    ->taskGitListStagedFiles()
                    ->setPaths(['*.php' => true])
                    ->setDiffFilter(['d' => false])
                    ->setAssetNamePrefix('staged.'))
                ->addTask($this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->setWorkingDirectory('.')
                    ->deferTaskConfiguration('setPaths', 'staged.fileNames'))
                ->addTask($this
                    ->taskPhpcsLintInput($options)
                    ->deferTaskConfiguration('setFiles', 'files')
                    ->deferTaskConfiguration('setIgnore', 'phpcsXml.exclude-patterns'));
        }

        return $this->taskPhpcsLintFiles($options);
    }

    /**
     * @return array<string>
     */
    protected function getTestSuiteNames(): array
    {
        if (!$this->testSuiteNames) {
            $filePath = $this->fs->exists('phpunit.xml')
                ? 'phpunit.xml'
                : 'phpunit.dist.xml';

            $xml = new \DOMDocument();
            $xml->loadXML($this->fs->readFile($filePath));
            $xpath = new \DOMXPath($xml);
            /** @var false|\DOMNodeList<\DOMElement> $elements */
            $elements = $xpath->query('/phpunit/testsuites/testsuite');
            if ($elements) {
                foreach ($elements as $element) {
                    $this->testSuiteNames[] = $element->getAttribute('name');
                }
            }
        }

        return $this->testSuiteNames;
    }

    /**
     * @param array<string> $suiteNames
     */
    protected function validateArgTestSuiteNames(array $suiteNames): static
    {
        $validSuiteNames = $this->getTestSuiteNames();
        $invalidSuiteNames = array_diff($suiteNames, $validSuiteNames);
        if ($invalidSuiteNames) {
            throw new InvalidArgumentException(
                sprintf(
                    'The following test suite names are invalid: %s. Valid names are: %s.',
                    implode(', ', $invalidSuiteNames),
                    implode(', ', $validSuiteNames),
                ),
                1,
            );
        }

        return $this;
    }

    protected function getTaskPhpstanAnalyze(): TaskInterface
    {
        /** @var \Sweetchuck\LintReport\Reporter\VerboseReporter $verboseReporter */
        $verboseReporter = $this->getContainer()->get('lintVerboseReporter');
        $verboseReporter->setFilePathStyle('relative');

        return $this
            ->taskPhpstanAnalyze()
            ->setNoProgress(true)
            ->setNoInteraction(true)
            ->setErrorFormat('json')
            ->addLintReporter('lintVerboseReporter', $verboseReporter);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Command(name: 'release:build')]
    #[Help(
        description: 'Generates an executable PHAR file.',
    )]
    #[Option(
        name: 'destination',
        description: 'The destination file path.',
    )]
    #[Option(
        name: 'tag',
        description: 'The tag name.',
    )]
    public function cmdReleaseBuildExecute(
        string $destination = './artifacts/junit-merger.phar',
        array $options = [
            'tag' => '',
        ]
    ): TaskInterface {
        if (!$this->fs->isAbsolutePath($destination)) {
            $destination = getcwd() . "/$destination";
        }

        return $this
            ->collectionBuilder()
            ->addCode($this->getTaskReleaseBuildInit())
            ->addTask($this->getTaskReleaseBuildPrepareWorkingDirectory())
            ->addTask($this->getTaskReleaseBuildCopyProjectCollect())
            ->addTask($this->getTaskReleaseBuildPharCopyProjectDoIt())
            ->addTask($this->taskComposerInstall()->option('no-dev'))
            ->addTask($this->getTaskReleaseBuildComposerPackagePaths())
            ->addCode($this->getTaskReleaseBuildPhar($destination, $options['tag']));
    }

    protected function getTaskReleaseBuildInit(): \Closure
    {
        return function (RoboStateData $data): int {
            $this->mainState = $data;
            $data['srcDir'] = getcwd();

            return 0;
        };
    }

    protected function getTaskReleaseBuildPrepareWorkingDirectory(): TaskInterface
    {
        return $this
            ->taskTmpDir(basename(__DIR__), dirname(__DIR__))
            ->cwd();
    }

    protected function getTaskReleaseBuildCopyProjectCollect(): TaskInterface
    {
        return $this
            ->taskGitListFiles()
            ->setAssetNamePrefix('project.')
            ->deferTaskConfiguration('setWorkingDirectory', 'srcDir');
    }

    protected function getTaskReleaseBuildPharCopyProjectDoIt(): TaskInterface
    {
        return $this
            ->taskForEach()
            ->iterationMessage('Something happening with {key}', ['foo' => 'bar'])
            ->deferTaskConfiguration('setIterable', 'project.files')
            ->withBuilder(function (CollectionBuilder $builder, string $fileName) {
                $builder->addTask($this->taskFilesystemStack()->copy(
                    $this->mainState['srcDir'] . '/' . $fileName,
                    "./$fileName",
                ));
            });
    }

    protected function getTaskReleaseBuildComposerPackagePaths(): TaskInterface
    {
        $task = $this->taskComposerPackagePaths();
        $task->deferTaskConfiguration('setWorkingDirectory', 'path');

        return $task;
    }

    protected function getTaskReleaseBuildPhar(string $pharPathname, string $version): \Closure
    {
        return function () use ($pharPathname, $version): int {
            $this->logger->info(
                "Create PHAR; version: {version} ; path: {pharPathname}",
                [
                    'pharPathname' => $pharPathname,
                    'version' => $version,
                ],
            );
            $vendorDir = 'vendor';

            $filesExtra = [
                $this->mainState['path'] . '/composer.json',
            ];
            $files = new \AppendIterator();
            $files->append(
                (new Finder())
                    ->in('./src/')
                    ->files()
                    ->name('*.php')
                    ->getIterator(),
            );
            $files->append(
                (new Finder())
                    ->in("./$vendorDir/")
                    ->files()
                    ->notPath("psr/log/Psr/Log/Test")
                    ->notPath("bin")
                    ->notPath("tests")
                    ->notName('composer.json')
                    ->notName('composer.lock')
                    ->notName('codeception*.*')
                    ->notName('phpcs.xml')
                    ->notName('phpcs.xml.dist')
                    ->notName('phpunit.xml')
                    ->notName('phpunit.xml.dist')
                    ->notName('robo.yml')
                    ->notName('robo.yml.dist')
                    ->notName('RoboFile.php')
                    ->notName('*.md')
                    ->ignoreVCS(true)
                    ->getIterator(),
            );

            $packageDirs = (new Finder())
                ->in($vendorDir)
                ->directories()
                ->depth(1);

            /** @var \Symfony\Component\Finder\SplFileInfo $packageDir */
            foreach ($packageDirs as $packageDir) {
                if (!$packageDir->isLink()) {
                    continue;
                }

                $packageFiles = (new Finder())
                    ->in((string) realpath($packageDir->getPathname()))
                    ->files()
                    ->notPath('bin')
                    ->notPath('reports')
                    ->notPath('tests')
                    ->notPath('Test')
                    ->notPath('vendor')
                    ->notName('codeception.*')
                    ->notName('composer.json')
                    ->notName('composer.lock')
                    ->notName('phpcs.xml')
                    ->notName('phpcs.xml.dist')
                    ->notName('phpunit.xml')
                    ->notName('phpunit.xml.dist')
                    ->notName('robo.yml')
                    ->notName('robo.yml.dist')
                    ->notName('RoboFile.php')
                    ->notName('*.md')
                    ->ignoreVCS(true);
                foreach ($packageFiles as $packageFile) {
                    $filesExtra[] = $this->mainState['path']
                        . '/' . $packageDir->getPathname()
                        . '/' . $packageFile->getRelativePathname();
                }
            }

            $files->append(new \ArrayIterator($filesExtra));

            $appName = 'junit-merger';
            if (file_exists($pharPathname)) {
                unlink($pharPathname);
            }

            $startFile = "bin/$appName";
            $startContent = file($startFile) ?: [];
            array_shift($startContent);
            if ($version !== '') {
                $startContent = preg_replace(
                    '/^\$version = \'.*?\';$/m',
                    sprintf('$version = %s;', var_export($version, true)),
                    $startContent,
                );
            }

            $this->fs->mkdir(dirname($pharPathname), 0777 - umask());
            $phar = new \Phar($pharPathname, 0);
            $phar->buildFromIterator($files, $this->mainState['path']);
            $phar->addFromString($startFile, implode('', $startContent));
            $phar->setStub($this->getPharStubCode($appName, $startFile));
            chmod($pharPathname, 0777 - umask());

            return 0;
        };
    }

    protected function getPharStubCode(string $appName, string $startFile): string
    {
        return sprintf(
            <<<'PHP'
                #!/usr/bin/env php
                <?php
                Phar::mapPhar(%s);
                set_include_path(%s . get_include_path());
                require(%s);
                __HALT_COMPILER();
                PHP,
            var_export($appName, true),
            var_export("phar://$appName/", true),
            var_export($startFile, true),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Command(name: 'phar:content')]
    #[Help(
        description: 'Lists the content of a PHAR file.',
    )]
    #[Option(
        name: 'format',
        description: 'The output format.',
    )]
    public function cmdPharContentExecute(
        string $path = './artifacts/junit-merger.phar',
        array $options = [
            'format' => 'yaml',
        ],
    ): CommandResult {
        $path = realpath($path);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("phar://$path"));
        $data = [];
        foreach ($files as $file) {
            $data[] = str_replace("phar://$path", '', $file);
        }

        return CommandResult::data($data);
    }
}
