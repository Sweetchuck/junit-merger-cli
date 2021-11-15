<?php

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\CommandData;
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
use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\Robo\Composer\ComposerTaskLoader;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
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

    protected ?RoboStateData $mainState = null;

    protected array $composerInfo = [];

    protected array $codeceptionInfo = [];

    /**
     * @var string[]
     */
    protected array $codeceptionSuiteNames = [];

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

    /**
     * RoboFile constructor.
     */
    public function __construct()
    {
        putenv('COMPOSER_DISABLE_XDEBUG_WARN=1');
        $this->fs = new Filesystem();
        $this
            ->initComposerInfo()
            ->initEnvVarNamePrefix()
            ->initEnvironmentTypeAndName();
    }

    /**
     * @hook pre-command @initLintReporters
     */
    public function initLintReporters()
    {
        $lintServices = BaseReporter::getServices();
        $container = $this->getContainer();
        foreach ($lintServices as $name => $class) {
            if ($container->has($name)) {
                continue;
            }

            if ($container instanceof LeagueContainer) {
                $container->share($name, $class);
            }
        }
    }

    /**
     * Git "pre-commit" hook callback.
     *
     * @command githook:pre-commit
     *
     * @hidden
     *
     * @initLintReporters
     */
    public function githookPreCommit(): CollectionBuilder
    {
        $this->gitHook = 'pre-commit';

        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskCodeceptRunSuites());
    }

    /**
     * @hook validate test
     */
    public function inputSuitNamesValidateOptionalArg(CommandData $commandData)
    {
        $args = $commandData->arguments();
        $this->validateArgCodeceptionSuiteNames($args['suiteNames']);
    }

    /**
     * @command config
     */
    public function cmdConfigExecute()
    {
        $this->output()->write(Yaml::dump($this->getConfig()->export(), 99));
    }

    /**
     * Run the Robo unit tests.
     *
     * @command test
     */
    public function test(
        array $suiteNames,
        array $options = [
            'debug' => false,
            'php-executable' => 'coverage_reporter_xdebug',
        ]
    ): CollectionBuilder {
        return $this->getTaskCodeceptRunSuites($suiteNames, $options);
    }

    /**
     * Run code style checkers.
     *
     * @command lint
     *
     * @initLintReporters
     */
    public function lint(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint());
    }

    protected function errorOutput(): ?OutputInterface
    {
        $output = $this->output();

        return ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;
    }

    /**
     * @return $this
     */
    protected function initEnvVarNamePrefix()
    {
        $this->envVarNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    /**
     * @return $this
     */
    protected function initEnvironmentTypeAndName()
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

    protected function getPhpExecutable($key): array
    {
        $executables = $this->getConfig()->get('php.executables');
        $definition = array_replace_recursive(
            [
                'envVars' => [],
                'binary' => 'php',
                'args' => [],
            ],
            $executables[$key] ?? [],
        );

        $argFilter = function ($value) {
            return !empty($value['enabled']);
        };
        $argComparer = function (array $a, array $b) {
            return ($a['weight'] ?? 99) <=> ($b['weight'] ?? 99);
        };
        $definition['envVars'] = array_filter($definition['envVars']);
        $definition['args'] = array_filter($definition['args'], $argFilter);
        uasort($definition['args'], $argComparer);

        return $definition;
    }

    /**
     * @return $this
     */
    protected function initComposerInfo()
    {
        $composerFileName = getenv('COMPOSER') ?: 'composer.json';
        if ($this->composerInfo || !is_readable($composerFileName)) {
            return $this;
        }

        $this->composerInfo = json_decode(file_get_contents($composerFileName), true);
        [$this->packageVendor, $this->packageName] = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function initCodeceptionInfo()
    {
        if ($this->codeceptionInfo) {
            return $this;
        }

        if (is_readable('codeception.yml')) {
            $this->codeceptionInfo = Yaml::parse(file_get_contents('codeception.yml'));
        } else {
            $this->codeceptionInfo = [
                'paths' => [
                    'tests' => 'tests',
                    'log' => 'tests/_output',
                ],
            ];
        }

        return $this;
    }

    protected function getTaskCodeceptRunSuites(array $suiteNames = [], array $options = []): CollectionBuilder
    {
        if (!$suiteNames) {
            $suiteNames = ['all'];
        }

        $cb = $this->collectionBuilder();
        foreach ($suiteNames as $suiteName) {
            $cb->addTask($this->getTaskCodeceptRunSuite($suiteName, $options));
        }

        return $cb;
    }

    protected function getTaskCodeceptRunSuite(string $suite, array $options = []): CollectionBuilder
    {
        $options = array_replace_recursive(
            [
                'php-executable' => 'coverage_reporter_xdebug',
            ],
            $options,
        );

        $this->initCodeceptionInfo();

        $withCoverageHtml = $this->environmentType === 'dev';
        $withCoverageXml = $this->environmentType === 'ci';

        $withUnitReportHtml = $this->environmentType === 'dev';
        $withUnitReportXml = $this->environmentType === 'ci';

        $logDir = $this->getLogDir();

        $cmd = [];

        $php = $this->getPhpExecutable($options['php-executable']);
        foreach ($php['envVars'] as $name => $value) {
            $cmd[] = sprintf('%s=%s', $name, $value);
        }
        $cmd[] = escapeshellcmd($php['binary']);
        foreach ($php['args'] as $argDef) {
            ksort($argDef['args']);
            $cmd = array_merge($cmd, $argDef['args']);
        }

        $cmd[] = "{$this->binDir}/codecept";

        $cmd[] = '--ansi';
        $cmd[] = '--verbose';
        if (!empty($options['debug'])) {
            $cmd[] = '--debug';
        }

        $cb = $this->collectionBuilder();
        if ($withCoverageHtml) {
            $cmd[] = "--coverage-html=human/coverage/$suite/html";

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/human/coverage/$suite")
            );
        }

        if ($withCoverageXml) {
            $cmd[] = "--coverage-xml=machine/coverage/$suite/coverage.xml";
        }

        if ($withCoverageHtml || $withCoverageXml) {
            $cmd[] = "--coverage=machine/coverage/$suite/coverage.php";

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/machine/coverage/$suite")
            );
        }

        if ($withUnitReportHtml) {
            $cmd[] = "--html=human/junit/junit.$suite.html";

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/human/junit")
            );
        }

        if ($withUnitReportXml) {
            $jUnitFilePath = "machine/junit/junit.$suite.xml";
            $dirToCreate = dirname("$logDir/$jUnitFilePath");

            $cmd[] = "--xml=$jUnitFilePath";

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir($dirToCreate)
            );
        }

        $cmd[] = 'run';
        if ($suite !== 'all') {
            $cmd[] = $suite;
        }

        if ($this->environmentType === 'ci' && $this->environmentName === 'jenkins') {
            // Jenkins has to use a post-build action to mark the build "unstable".
            $cmd[] = '||';
            $cmd[] = '[[ "${?}" == "1" ]]';
        }

        $command = [
            getenv('SHELL') ?: 'bash',
            '-c',
            implode(' ', $cmd),
        ];

        return $cb
            ->addCode(function () use ($command) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'Codeception',
                        '{command}' => $command[2],
                    ]
                ));

                $process = new Process($command);

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
                ->setDestination('tests/_output/machine/checkstyle/phpcs.psr2.xml');
        }

        if ($this->gitHook === 'pre-commit') {
            return $this
                ->collectionBuilder()
                ->addTask($this
                    ->taskPhpcsParseXml()
                    ->setAssetNamePrefix('phpcsXml.'))
                ->addTask($this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->deferTaskConfiguration('setPaths', 'phpcsXml.files'))
                ->addTask($this
                    ->taskPhpcsLintInput($options)
                    ->deferTaskConfiguration('setFiles', 'files')
                    ->deferTaskConfiguration('setIgnore', 'phpcsXml.exclude-patterns'));
        }

        return $this->taskPhpcsLintFiles($options);
    }

    protected function getLogDir(): string
    {
        $this->initCodeceptionInfo();

        return !empty($this->codeceptionInfo['paths']['log']) ?
            $this->codeceptionInfo['paths']['log']
            : 'tests/_output';
    }

    protected function getCodeceptionSuiteNames(): array
    {
        if (!$this->codeceptionSuiteNames) {
            $this->initCodeceptionInfo();

            $suiteFiles = Finder::create()
                ->in($this->codeceptionInfo['paths']['tests'])
                ->files()
                ->name('*.suite.dist.yml')
                ->name('*.suite.yml')
                ->depth(0);

            foreach ($suiteFiles as $suiteFile) {
                $parts = explode('.', $suiteFile->getBasename());
                $this->codeceptionSuiteNames[] = reset($parts);
            }
        }

        return $this->codeceptionSuiteNames;
    }

    /**
     * @return $this
     */
    protected function validateArgCodeceptionSuiteNames(array $suiteNames)
    {
        $invalidSuiteNames = array_diff($suiteNames, $this->getCodeceptionSuiteNames());
        if ($invalidSuiteNames) {
            throw new InvalidArgumentException(
                'The following Codeception suite names are invalid: ' . implode(', ', $invalidSuiteNames),
                1
            );
        }

        return $this;
    }

    /**
     * Generates an executable PHAR file.
     *
     * @command release:build
     */
    public function cmdReleaseBuildExecute(
        string $destination = './artifacts/junit-merger.phar',
        array $options = [
            'tag' => '',
        ]
    ) {
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
            ->taskTmpDir(basename(__DIR__), realpath('..'))
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
                    ->in(realpath($packageDir->getPathname()))
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
            $startContent = file($startFile);
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
     * @command phar:content
     */
    public function cmdPharContentExecute(string $path = './artifacts/junit-merger.phar')
    {
        $path = realpath($path);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("phar://$path"));
        $output = $this->output();
        foreach ($files as $file) {
            $output->writeln(str_replace("phar://$path", '', $file));
        }
    }
}
