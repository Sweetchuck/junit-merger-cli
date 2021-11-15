<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Test\Helper;

use Codeception\Module;

class Acceptance extends Module
{
    protected $requiredFields = [];

    protected $config = [
        'pharPath' => './artifacts/junit-merger.phar',
    ];

    public function grabPharPath(): string
    {
        return $this->config['pharPath'];
    }
}
