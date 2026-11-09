<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\LifecycleRegistry;
use App\Twig\BadgeExtension;
use App\Twig\StatusVocabulary;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * W7 / Gate 78: the same value has the same tone everywhere.
 *
 * Covers every source of a word-based pill tone: the places of every workflow
 * (`metadata.tone`), the named vocabularies of `_status_vocabulary`, the severity
 * scale behind `pill_tone_severity()` and the status words behind
 * `pill_tone_status()` / `badge_status()`.
 */
final class StatusToneConsistencyTest extends KernelTestCase
{
    #[Test]
    public function everyToneIsAnAuroraTone(): void
    {
        $tables = ['STATUS_WORDS' => StatusVocabulary::STATUS_WORDS, 'SEVERITY' => StatusVocabulary::SEVERITY]
            + StatusVocabulary::VOCABULARIES;
        foreach ($tables as $name => $table) {
            foreach ($table as $value => $tone) {
                self::assertContains($tone, LifecycleRegistry::TONES, sprintf('%s.%s', $name, $value));
            }
        }
    }

    #[Test]
    public function everyWorkflowPlaceCarriesTheToneOfItsStatusWord(): void
    {
        $checked = 0;
        foreach ($this->workflowPlaces() as $workflow => $places) {
            foreach ($places as $place) {
                $name = $place['name'] ?? null;
                self::assertIsString($name, sprintf('%s: a place without name', $workflow));
                self::assertArrayHasKey(
                    $name,
                    StatusVocabulary::STATUS_WORDS,
                    sprintf('%s.%s: status word missing in StatusVocabulary::STATUS_WORDS', $workflow, $name),
                );
                self::assertSame(
                    StatusVocabulary::STATUS_WORDS[$name],
                    $place['metadata']['tone'] ?? null,
                    sprintf('%s.%s: metadata.tone differs from STATUS_WORDS', $workflow, $name),
                );
                ++$checked;
            }
        }
        self::assertGreaterThan(150, $checked);
    }

    #[Test]
    public function statusVocabulariesUseTheStatusWordTones(): void
    {
        foreach (StatusVocabulary::STATUS_FAMILY as $vocabulary) {
            self::assertArrayHasKey($vocabulary, StatusVocabulary::VOCABULARIES);
            foreach (StatusVocabulary::VOCABULARIES[$vocabulary] as $value => $tone) {
                if (isset(StatusVocabulary::STATUS_WORDS[$value])) {
                    self::assertSame(
                        StatusVocabulary::STATUS_WORDS[$value],
                        $tone,
                        sprintf('vocabulary %s: "%s" has another tone than the status word', $vocabulary, $value),
                    );
                }
            }
        }
    }

    #[Test]
    public function severityVocabulariesUseTheSeverityScale(): void
    {
        foreach (StatusVocabulary::SEVERITY_FAMILY as $vocabulary) {
            self::assertArrayHasKey($vocabulary, StatusVocabulary::VOCABULARIES);
            foreach (StatusVocabulary::VOCABULARIES[$vocabulary] as $value => $tone) {
                if (isset(StatusVocabulary::SEVERITY[$value])) {
                    self::assertSame(
                        StatusVocabulary::SEVERITY[$value],
                        $tone,
                        sprintf('vocabulary %s: "%s" has another tone than the severity scale', $vocabulary, $value),
                    );
                }
            }
        }
    }

    #[Test]
    public function helpersReadTheOneTable(): void
    {
        $badges = new BadgeExtension();
        foreach (StatusVocabulary::STATUS_WORDS as $word => $tone) {
            self::assertSame($tone, $badges->getStatusTone($word), 'pill_tone_status ' . $word);
            self::assertSame('fa-status-pill fa-status-pill--' . $tone, $badges->getStatusBadgeClass($word));
        }
        foreach (StatusVocabulary::SEVERITY as $word => $tone) {
            self::assertSame($tone, $badges->getSeverityTone((string) $word), 'pill_tone_severity ' . $word);
            self::assertSame('fa-status-pill fa-status-pill--' . $tone, $badges->getPriorityBadgeClass((string) $word));
            self::assertSame('fa-status-pill fa-status-pill--' . $tone, $badges->getRiskBadgeClass((string) $word));
        }
    }

    /**
     * Before W7 no entity carried the `#[Lifecycle]` attribute, so every status
     * outside the 5-stage document flow rendered neutral. The registry now reads
     * the entity's workflow: every registered entity gets its places and tones.
     */
    #[Test]
    public function lifecycleToneComesFromTheEntityWorkflow(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(LifecycleRegistry::class);
        $places = $this->workflowPlaces();

        $entityTypes = new EntityTypeRegistry();
        foreach ($entityTypes->knownSlugs() as $slug) {
            $entry = $entityTypes->lookup($slug);
            self::assertNotNull($entry);
            $expected = [];
            foreach ($places[$entry['workflow']] ?? [] as $place) {
                $expected[$place['name']] = $place['metadata']['tone'];
            }
            self::assertNotSame([], $expected, $slug . ': workflow not found in config/workflows');
            self::assertSame(array_keys($expected), $registry->getStages($entry['class']), $slug . ': places');
            foreach ($expected as $status => $tone) {
                self::assertSame($tone, $registry->getTone($entry['class'], $status), $slug . '.' . $status);
            }
        }

        // the cases the 5-stage fallback rendered neutral
        self::assertSame('warning', $registry->getTone(\App\Entity\AuditFinding::class, 'open'));
        self::assertSame('warning', $registry->getTone(\App\Entity\Incident::class, 'reported'));
        self::assertSame('warning', $registry->getTone(\App\Entity\Risk::class, 'identified'));
        self::assertSame('success', $registry->getTone(\App\Entity\Asset::class, 'active'));
        self::assertSame(['assessed'], $registry->getAllowedTransitions(\App\Entity\Risk::class, 'identified'));
    }

    /** @return array<string, list<array{name: string, metadata?: array{tone?: string}}>> */
    private function workflowPlaces(): array
    {
        $out = [];
        foreach (glob(\dirname(__DIR__, 2) . '/config/workflows/*.yaml') ?: [] as $file) {
            foreach (Yaml::parseFile($file)['framework']['workflows'] ?? [] as $name => $workflow) {
                $out[$name] = $workflow['places'];
            }
        }

        return $out;
    }
}
