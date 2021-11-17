<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Sweetchuck\JunitMerger\JunitMergerInterface;
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
                'a',
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

        $inputFiles = $this->createInputFilesIterator($input);
        $output = $this->createOutput($input);
        $merger = $this->createMerger($input);

        $merger->mergeXmlFiles($inputFiles, $output);
        $this->tearDownOutput($output);

        return 0;
    }

    protected function createInputFilesIterator(InputInterface $input): \Iterator
    {
        $inputFiles = $input->getArgument('input-files');

        return count($inputFiles) ?
            new \ArrayIterator($inputFiles)
            : new \SplFileObject('php://stdin');
    }

    protected function createOutput(InputInterface $input): OutputInterface
    {
        // @todo Error handling.
        // @todo Create parent directories.
        $fileName = $input->getOption('output-file');
        $fileHandler = $fileName === null || $fileName === '' ?
            \STDOUT
            : fopen($fileName, 'w+');

        return new StreamOutput(
            $fileHandler,
            OutputInterface::VERBOSITY_VERY_VERBOSE | OutputInterface::OUTPUT_RAW,
            false,
        );
    }

    protected function tearDownOutput(OutputInterface $output)
    {
        if ($output instanceof StreamOutput) {
            fclose($output->getStream());
        }

        return $this;
    }

    protected function createMerger(InputInterface $input): JunitMergerInterface
    {
        $handler = $input->getOption('handler');
        $serviceId = "junit_merger.$handler";

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->container->get($serviceId);
    }
}
