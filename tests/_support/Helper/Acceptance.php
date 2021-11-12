<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Test\Helper;

use Codeception\Module;
use Symfony\Component\Filesystem\Filesystem;

class Acceptance extends Module
{
    protected array $tmpDirs = [];

    /**
     * {@inheritdoc}
     */
    public function _beforeSuite($settings = [])
    {
        parent::_beforeSuite($settings);

        register_shutdown_function(function () {
            (new Filesystem())->remove($this->tmpDirs);
            $this->tmpDirs = [];
        });

        $this->buildPhar();
    }

    public function _afterSuite()
    {
        (new Filesystem())->remove($this->tmpDirs);
        $this->tmpDirs = [];

        parent::_afterSuite();
    }

    protected function buildPhar()
    {
        $destination = tempnam(sys_get_temp_dir(), 'junit-merger-cli-test-article-');
        unlink($destination);
        mkdir($destination, 0777 - umask(), true);
        $this->tmpDirs[] = $destination;
        $destination .= '/junit-merger.phar';

        $command = sprintf(
            './vendor/bin/robo phar %s',
            escapeshellarg($destination),
        );

        exec($command, $output, $exitCode);
        if ($exitCode) {
            $this->fail(implode(\PHP_EOL, $output));
        }

        return $this;
    }

    public function grabPharPath(): string
    {
        return $this->tmpDirs[0] . '/junit-merger.phar';
    }
}
