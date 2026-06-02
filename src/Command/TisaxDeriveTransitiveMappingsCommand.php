<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Derive DIRECT TISAX → NIS2 / DORA mapping edges by transitive composition over
 * the shared ISO 27001 anchor: TISAX→ISO27001 ∘ ISO27001→{NIS2,DORA}.
 *
 * There is no hand-authored TISAX↔NIS2 or TISAX↔DORA mapping (and the EU
 * directives are not a control catalogue), but ISO 27001 is the bridge the whole
 * market uses ("27001 covers ~70% of NIS2 Art. 21"). Composing the two existing
 * legs materialises the reuse so an imported TISAX fulfilment can inherit onto
 * NIS2/DORA in ONE hop instead of an undocumented two-hop chain.
 *
 * Each derived edge is flagged source='transitive_via_iso27001', confidence 'low'
 * and percentage = round(p_tisax_iso * p_iso_target / 100) — a transitively-
 * derived suggestion, never presented as an authoritative direct mapping. Idempotent.
 */
#[AsCommand(
    name: 'app:tisax:derive-transitive-mappings',
    description: 'Compose TISAX→ISO27001 ∘ ISO27001→{NIS2,DORA} into direct TISAX→NIS2/DORA mapping edges',
)]
final class TisaxDeriveTransitiveMappingsCommand extends Command
{
    private const TARGET_CODES = ['NIS2', 'DORA'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Write the derived edges (default: dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        // Leg 1: TISAX source-req → ISO target-req (+ percentage).
        $tisaxToIso = $this->db->fetchAllAssociative(
            "SELECT cm.source_requirement_id AS tisax_id, cm.target_requirement_id AS iso_id, cm.mapping_percentage AS pct
             FROM compliance_mapping cm
             JOIN compliance_requirement rs ON rs.id = cm.source_requirement_id
             JOIN compliance_requirement rt ON rt.id = cm.target_requirement_id
             JOIN compliance_framework fs ON fs.id = rs.framework_id
             JOIN compliance_framework ft ON ft.id = rt.framework_id
             WHERE fs.code = 'TISAX' AND ft.code = 'ISO27001'",
        );

        // Leg 2: ISO source-req → {NIS2,DORA} target-req, indexed by ISO req id.
        $isoToTarget = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT cm.source_requirement_id AS iso_id, cm.target_requirement_id AS tgt_id, cm.mapping_percentage AS pct, ft.code AS tgt_code
             FROM compliance_mapping cm
             JOIN compliance_requirement rs ON rs.id = cm.source_requirement_id
             JOIN compliance_requirement rt ON rt.id = cm.target_requirement_id
             JOIN compliance_framework fs ON fs.id = rs.framework_id
             JOIN compliance_framework ft ON ft.id = rt.framework_id
             WHERE fs.code = 'ISO27001' AND ft.code IN (:codes)",
            ['codes' => self::TARGET_CODES],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ) as $row) {
            $isoToTarget[(int) $row['iso_id']][] = $row;
        }

        // Compose. Keep the best (highest) percentage per (tisax, target) pair.
        $derived = [];
        foreach ($tisaxToIso as $leg1) {
            $isoId = (int) $leg1['iso_id'];
            foreach ($isoToTarget[$isoId] ?? [] as $leg2) {
                $key = $leg1['tisax_id'] . ':' . $leg2['tgt_id'];
                $pct = (int) round(((int) $leg1['pct']) * ((int) $leg2['pct']) / 100);
                if (!isset($derived[$key]) || $pct > $derived[$key]['pct']) {
                    $derived[$key] = [
                        'tisax_id' => (int) $leg1['tisax_id'],
                        'tgt_id' => (int) $leg2['tgt_id'],
                        'tgt_code' => $leg2['tgt_code'],
                        'pct' => $pct,
                    ];
                }
            }
        }

        // Drop pairs that already have a mapping (idempotent).
        $existing = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT source_requirement_id AS s, target_requirement_id AS t FROM compliance_mapping',
        ) as $row) {
            $existing[$row['s'] . ':' . $row['t']] = true;
        }

        $byTarget = ['NIS2' => 0, 'DORA' => 0];
        $created = 0;
        foreach ($derived as $key => $d) {
            if (isset($existing[$key])) {
                continue;
            }
            $byTarget[$d['tgt_code']] = ($byTarget[$d['tgt_code']] ?? 0) + 1;
            if (!$force) {
                continue;
            }
            $src = $this->em->getReference(ComplianceRequirement::class, $d['tisax_id']);
            $tgt = $this->em->getReference(ComplianceRequirement::class, $d['tgt_id']);
            $mapping = new ComplianceMapping();
            $mapping->setSourceRequirement($src);
            $mapping->setTargetRequirement($tgt);
            $mapping->setMappingPercentage($d['pct']);
            $mapping->setMappingType('partial');
            $mapping->setConfidence('low');
            $mapping->setSource('transitive_via_iso27001');
            $mapping->setBidirectional(false);
            $mapping->setMappingRationale('Transitively derived: TISAX → ISO 27001 → ' . $d['tgt_code']
                . ' via the shared ISO anchor. Suggestion only — confirm before relying on it.');
            $this->em->persist($mapping);
            ++$created;
            if ($created % 200 === 0) {
                $this->em->flush();
                $this->em->clear(ComplianceMapping::class);
            }
        }
        if ($force) {
            $this->em->flush();
        }

        $io->table(['Target', 'New edges'], [['NIS2', $byTarget['NIS2'] ?? 0], ['DORA', $byTarget['DORA'] ?? 0]]);
        $io->success(sprintf(
            '%s %d transitive TISAX→{NIS2,DORA} edges (of %d composed pairs).',
            $force ? 'Created' : 'Would create',
            array_sum($byTarget),
            count($derived),
        ));
        if (!$force) {
            $io->note('Dry-run — re-run with --force to write.');
        }

        return Command::SUCCESS;
    }
}
