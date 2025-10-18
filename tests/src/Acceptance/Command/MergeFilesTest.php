<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Tests\Acceptance\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

class MergeFilesTest extends TestCase
{

    protected function getProjectRootDir(): string
    {
        return __DIR__ . '/../../../..';
    }

    public function getPharPath(): string
    {
        return Path::join(
            $this->getProjectRootDir(),
            'artifacts',
            'junit-merger.phar',
        );
    }

    public function testMergeFilesInputFileNamesAsArgs(): void
    {
        $projectRootDir = $this->getProjectRootDir();
        $fixturesDir = "$projectRootDir/vendor/sweetchuck/junit-merger/tests/fixtures";
        $pharPath = $this->getPharPath();

        $command = [
            $pharPath,
            'merge:files',
            "$fixturesDir/junit/a.xml",
            "$fixturesDir/junit/b.xml",
        ];
        $process = new Process($command);
        $process->run();

        static::assertSame(
            '',
            $process->getErrorOutput(),
            'stdError'
        );
        static::assertSame(
            0,
            $process->getExitCode(),
            'exitCode',
        );
        static::assertSame(
            file_get_contents("$fixturesDir/junit-expected/a-b.xml") ?: '',
            $process->getOutput(),
            'stdOutput',
        );
    }

    public function testMergeFilesInputFileNamesFromStdInput(): void
    {
        $projectRootDir = $this->getProjectRootDir();
        $fixturesDir = "$projectRootDir/vendor/sweetchuck/junit-merger/tests/fixtures";
        $pharPath = $this->getPharPath();

        $command = [
            $pharPath,
            'merge:files',
        ];
        $process = new Process(
            command: $command,
            input: "$fixturesDir/junit/a.xml\n$fixturesDir/junit/b.xml",
        );
        $process->run();
        static::assertSame(
            '',
            $process->getErrorOutput(),
            'stdError',
        );
        static::assertSame(
            0,
            $process->getExitCode(),
            'exitCode',
        );
        static::assertSame(
            file_get_contents("$fixturesDir/junit-expected/a-b.xml"),
            $process->getOutput(),
            'stdOutput',
        );
    }
}
