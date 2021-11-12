<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use SplFileObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class MergeFiles extends Command implements ContainerAwareInterface, LoggerAwareInterface
{

    use ContainerAwareTrait;
    use LoggerAwareTrait;

    /**
     * {@inheritdoc}
     */
    protected static $defaultName = 'merge:files';

    protected array $handlerAllowedValues = [
        'substr',
        'dom_read',
        'dom_read_write',
    ];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Merges two or more JUnit XML files into one.')
            ->addOption(
                'output-file',
                'o',
                InputOption::VALUE_REQUIRED,
                'Destination for the final JUnit XML file.',
                'php://stdout',
            )
            ->addOption(
                'handler',
                'p',
                InputOption::VALUE_REQUIRED,
                'Allowed values: ' . implode(', ', $this->handlerAllowedValues),
                'substr',
            )
            ->addArgument(
                'input-files',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'JUnit XML filenames to merge into one file. By default filenames will be read from the stdInput.',
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->validate($input);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return max($e->getCode(), 1);
        }

        $xmlFiles = new \ArrayIterator($input->getArgument('input-files'));
        if (!$xmlFiles->count()) {
            $xmlFiles = new SplFileObject('php://stdin');
        }

        $outputFile = $input->getOption('output-file') ?: 'php://stdout';
        $output = new StreamOutput(fopen($outputFile, 'w+'));

        $handler = $input->getOption('handler');
        $serviceId = "junit_merger.$handler";
        /** @var \Sweetchuck\JunitMerger\JunitMergerInterface $junitMerger */
        $junitMerger = $this->container->get($serviceId);
        $junitMerger->mergeXmlFiles($xmlFiles, $output);
        fclose($output->getStream());

        return 0;
    }

    protected function validate(InputInterface $input)
    {
        $handler = $input->getOption('handler');
        $handlerServiceId = "junit_merger.$handler";
        if (!$this->container->has($handlerServiceId)) {
            throw new \RuntimeException(
                sprintf(
                    'invalid handler: %s; allowed values: %s',
                    $handler,
                    implode(', ', $this->handlerAllowedValues),
                ),
            );
        }

        return $this;
    }
}
