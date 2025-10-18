<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli;

use Sweetchuck\JunitMerger\JunitMergerDomRead;
use Sweetchuck\JunitMerger\JunitMergerDomReadWrite;
use Sweetchuck\JunitMerger\JunitMergerSubstr;
use Sweetchuck\JunitMergerCli\Command\MergeFiles;
use Symfony\Component\Console\Application as ApplicationBase;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition as ServiceDefinition;
use Symfony\Component\DependencyInjection\Reference as ServiceReference;

class Application extends ApplicationBase
{

    public function initialize(): static
    {
        $container = new ContainerBuilder();

        $service = new ServiceDefinition(ConsoleOutput::class);
        $container->setDefinition('output', $service);

        $service = new ServiceDefinition(ConsoleLogger::class);
        $service->addArgument(new ServiceReference('output'));
        $container->setDefinition('logger', $service);

        $container->register('junit_merger.dom_read', JunitMergerDomRead::class);
        $container->register('junit_merger.dom_read_write', JunitMergerDomReadWrite::class);
        $container->register('junit_merger.substr', JunitMergerSubstr::class);

        $cmdMerge = new MergeFiles(
            name: 'merge:files',
            handlers: [
                'dom_read' => $container->get('junit_merger.dom_read'),
                'dom_read_write' => $container->get('junit_merger.dom_read_write'),
                'substr' => $container->get('junit_merger.substr'),
            ],
        );
        $cmdMerge->setLogger($container->get('logger'));
        $this->add($cmdMerge);

        return $this;
    }
}
