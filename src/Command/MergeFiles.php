<?php

declare(strict_types = 1);

namespace Sweetchuck\JunitMergerCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class MergeFiles extends Command implements LoggerAwareInterface
{

    use LoggerAwareTrait;

    /**
     * @return array<string, \Sweetchuck\JunitMerger\JunitMergerInterface>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * @param array<string, \Sweetchuck\JunitMerger\JunitMergerInterface> $handlers
     */
    public function setHandlers(array $handlers): static
    {
        $this->handlers = $handlers;

        return $this;
    }

    /**
     * @param array<string, \Sweetchuck\JunitMerger\JunitMergerInterface> $handlers
     */
    public function __construct(
        ?string $name = null,
        protected array $handlers = [],
    ) {
        parent::__construct($name);
    }


    /**
     * {@inheritdoc}
     */
    protected function configure(): void
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
                'Allowed values: ' . implode(', ', array_keys($this->handlers)),
                'substr',
                function (): array {
                    return array_keys($this->handlers);
                },
            )
            ->addArgument(
                'input-files',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'JUnit XML filenames to merge into one file. By default filenames will be read from the stdInput.',
            );
    }

    protected function validate(InputInterface $input): static
    {
        $handlerName = $input->getOption('handler');
        if (!isset($this->handlers[$handlerName])) {
            throw new \RuntimeException(
                sprintf(
                    'invalid handler: %s; allowed values: %s',
                    $handlerName,
                    implode(', ', array_keys($this->handlers)),
                ),
            );
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->validate($input);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());

            return max($e->getCode(), 1);
        }

        $inputFiles = $this->createInputFilesIterator($input);
        try {
            $output = $this->createOutput($input);
        } catch (\Throwable $error) {
            $this->logger->error($error->getMessage());

            return 1;
        }
        $handlerName = $input->getOption('handler');
        $merger = $this->handlers[$handlerName];

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
        $fileHandler = $fileName === null || $fileName === ''
            ? \STDOUT
            : fopen($fileName, 'w+');
        if (!$fileHandler) {
            // @todo Better error message.
            throw new \RuntimeException();
        }

        return new StreamOutput(
            $fileHandler,
            OutputInterface::VERBOSITY_VERY_VERBOSE | OutputInterface::OUTPUT_RAW,
            false,
        );
    }

    protected function tearDownOutput(OutputInterface $output): static
    {
        if ($output instanceof StreamOutput) {
            fclose($output->getStream());
        }

        return $this;
    }
}
