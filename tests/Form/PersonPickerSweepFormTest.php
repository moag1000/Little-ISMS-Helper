<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\Supplier;
use App\Entity\Vulnerability;
use App\Form\InterestedPartyType;
use App\Form\ISMSObjectiveType;
use App\Form\LocationType;
use App\Form\PatchType;
use App\Form\SupplierType;
use App\Form\VulnerabilityType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Person-picker sweep anti-regression: every swept FormType exposes the
 * User / Person / deputies cluster next to the legacy free-text field, and
 * lists all four in its SectionMap (check_form_sections.py parity).
 */
final class PersonPickerSweepFormTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, class-string, string}>
     */
    public static function formProvider(): iterable
    {
        yield 'objective' => [ISMSObjectiveType::class, ISMSObjective::class, 'responsiblePerson'];
        yield 'interested party' => [InterestedPartyType::class, InterestedParty::class, 'contactPerson'];
        yield 'location' => [LocationType::class, Location::class, 'responsiblePerson'];
        yield 'patch' => [PatchType::class, Patch::class, 'responsiblePerson'];
        yield 'supplier' => [SupplierType::class, Supplier::class, 'contactPerson'];
        yield 'vulnerability' => [VulnerabilityType::class, Vulnerability::class, 'responsiblePerson'];
    }

    /**
     * @param class-string $type
     * @param class-string $entity
     */
    #[Test]
    #[DataProvider('formProvider')]
    public function formExposesOwnerClusterAndLegacyField(string $type, string $entity, string $base): void
    {
        self::bootKernel();
        $deputies = str_starts_with($base, 'contact') ? 'contactDeputyPersons' : 'responsibleDeputyPersons';
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->create($type, new $entity(), ['csrf_protection' => false]);

        self::assertTrue($form->has($base . 'User'));
        self::assertTrue($form->has($base . 'Ref'));
        self::assertTrue($form->has($deputies));
        self::assertTrue($form->has($base), 'legacy free-text field must keep round-tripping');

        $sections = array_merge(...array_values($type::getSectionMap()));
        foreach ([$base . 'User', $base . 'Ref', $deputies, $base] as $field) {
            self::assertContains($field, $sections, sprintf('%s missing from %s section map', $field, $type));
        }
    }
}
