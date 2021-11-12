<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Tests\Unit\Command;

use Codeception\Test\Unit;
use org\bovigo\vfs\vfsStream;
use Sweetchuck\JunitMergerCli\Application;
use Sweetchuck\JunitMergerCli\Test\UnitTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Sweetchuck\JunitMergerCli\Command\MergeFiles
 */
class MergeFilesTest extends Unit
{
    protected UnitTester $tester;

    public function testExecute()
    {
        $vfs = vfsStream::setup(
            'root',
            0777,
            [
                __FUNCTION__ => [],
            ],
        );
        $junitMergerFixturesDir = './vendor/sweetchuck/junit-merger/tests/_data/fixtures';
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

        $this->tester->assertSame(0, $commandTester->getStatusCode(), 'exitCode');
        $this->tester->assertSame('', $commandTester->getDisplay(), 'stdOutput');
        $this->tester->assertSame('', $commandTester->getErrorOutput(), 'stdError');
        $this->tester->assertSame(
            file_get_contents("$junitMergerFixturesDir/junit-expected/a-b.xml"),
            file_get_contents($outputFile),
        );
    }
}
