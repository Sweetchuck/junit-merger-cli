<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Tests\Unit\Command;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sweetchuck\JunitMergerCli\Application;
use Sweetchuck\JunitMergerCli\Command\MergeFiles;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Application::class)]
#[CoversClass(MergeFiles::class)]
class MergeFilesTest extends TestCase
{

    public function testExecute(): void
    {
        $vfs = vfsStream::setup(
            'root',
            0777,
            [
                __FUNCTION__ => [],
            ],
        );
        $junitMergerFixturesDir = './vendor/sweetchuck/junit-merger/tests/fixtures';
        $outputFile = $vfs->url() . '/' . __FUNCTION__ . '/merged.xml';

        $application = new Application();
        $application->initialize();

        /** @var \Sweetchuck\JunitMergerCli\Command\MergeFiles $command */
        $command = $application->find('merge:files');
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'input-files' => [
                    "$junitMergerFixturesDir/junit/a.xml",
                    "$junitMergerFixturesDir/junit/b.xml",
                ],
                '--handler' => 'substr',
                '--output-file' => $outputFile,
            ],
            [
                'capture_stderr_separately' => true,
            ],
        );

        static::assertSame(0, $commandTester->getStatusCode(), 'exitCode');
        static::assertSame('', $commandTester->getDisplay(), 'stdOutput');
        static::assertSame('', $commandTester->getErrorOutput(), 'stdError');
        static::assertSame(
            file_get_contents("$junitMergerFixturesDir/junit-expected/a-b.xml"),
            file_get_contents($outputFile),
        );
    }
}
