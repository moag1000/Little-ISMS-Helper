<?php

declare(strict_types=1);

namespace App\Command;

use DOMDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use XSLTProcessor;

/**
 * Transforms a real GSTOOL XML export into the gstool_xml_v1 schema
 * used by GstoolXmlImporter.
 *
 * Real exports use SQL-Server-table-shaped XML with N_*, MB_*, MOD_*
 * prefixes. The bundled XSLT (tools/gstool-export-to-v1.xslt) maps the
 * most common shapes; tenants whose export-tool emits different field
 * names should adjust the XSLT and re-run.
 */
#[AsCommand(
    name: 'app:transform-gstool-xml',
    description: 'Transform a real GSTOOL XML export into the gstool_xml_v1 schema.',
)]
final class TransformGstoolXmlCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/tools/gstool-export-to-v1.xslt')]
        private readonly string $xsltPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'in',
                'i',
                InputOption::VALUE_REQUIRED,
                'Path to the real GSTOOL XML export.',
            )
            ->addOption(
                'out',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output path for the v1-schema XML.',
            )
            ->addOption(
                'xslt',
                null,
                InputOption::VALUE_OPTIONAL,
                'Override the bundled XSLT (defaults to tools/gstool-export-to-v1.xslt).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!extension_loaded('xsl')) {
            $io->error('PHP ext-xsl is not loaded. Install php-xsl or use xsltproc on the CLI directly.');
            return Command::FAILURE;
        }

        $in = (string) $input->getOption('in');
        $out = (string) $input->getOption('out');
        $xslt = (string) ($input->getOption('xslt') ?? $this->xsltPath);

        if ($in === '' || $out === '') {
            $io->error('Both --in and --out are required.');
            return Command::INVALID;
        }
        foreach ([$in, $xslt] as $f) {
            if (!is_file($f)) {
                $io->error(sprintf('File not found: %s', $f));
                return Command::FAILURE;
            }
        }

        $xml = new DOMDocument();
        $xml->preserveWhiteSpace = false;
        $xml->load($in, LIBXML_NONET);

        $sheet = new DOMDocument();
        $sheet->load($xslt, LIBXML_NONET);

        $proc = new XSLTProcessor();
        $proc->importStyleSheet($sheet);

        $result = $proc->transformToXml($xml);
        if ($result === false) {
            $io->error('XSLT transform failed.');
            return Command::FAILURE;
        }

        if (file_put_contents($out, $result) === false) {
            $io->error(sprintf('Failed to write output: %s', $out));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Transformed %s → %s (%d bytes).',
            $in,
            $out,
            strlen($result),
        ));
        $io->note('Now run: app:import-gstool-xml --tenant=… --file=' . $out . ' --dry-run');

        return Command::SUCCESS;
    }
}
