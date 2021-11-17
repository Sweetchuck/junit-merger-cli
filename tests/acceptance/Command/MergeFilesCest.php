<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Tests\Acceptance\Command;

use Sweetchuck\JunitMergerCli\Test\AcceptanceTester;

class MergeFilesCest
{
    public function mergeFilesInputFileNamesAsArgs(AcceptanceTester $I)
    {
        $fixturesDir = './vendor/sweetchuck/junit-merger/tests/_data/fixtures';

        $pharPath = $I->grabPharPath();
        $I->assertNotEmpty($pharPath);
        $I->runShellCommand(
            sprintf(
                '%s merge:files %s %s',
                escapeshellcmd($pharPath),
                escapeshellarg("$fixturesDir/junit/a.xml"),
                escapeshellarg("$fixturesDir/junit/b.xml"),
            ),
        );
        $I->assertSame(
            rtrim(file_get_contents("$fixturesDir/junit-expected/a-b.xml")),
            $I->grabShellOutput(),
            'stdOutput',
        );
    }

    public function mergeFilesInputFileNamesFromStdInput(AcceptanceTester $I)
    {
        $fixturesDir = './vendor/sweetchuck/junit-merger/tests/_data/fixtures';

        $pharPath = $I->grabPharPath();
        $I->assertNotEmpty($pharPath);

        $I->runShellCommand(
            sprintf(
                "find %s -name %s -or -name %s | %s merge:files",
                escapeshellarg("$fixturesDir/junit"),
                escapeshellarg('a.xml'),
                escapeshellarg('b.xml'),
                escapeshellcmd($pharPath),
            ),
        );
        $I->assertSame(
            rtrim(file_get_contents("$fixturesDir/junit-expected/a-b.xml")),
            $I->grabShellOutput(),
            'stdOutput',
        );
    }
}
