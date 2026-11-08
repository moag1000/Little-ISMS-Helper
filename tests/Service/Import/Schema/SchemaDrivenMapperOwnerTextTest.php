<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\Provider\OwnerImportFields;
use App\Service\Import\Schema\SchemaDrivenMapper;
use App\Service\ModuleConfigurationService;
use App\Service\Owner\OwnerText;
use App\Service\Owner\OwnerTextResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An owner column given as text ("owner": "Max Muster") fills the User or
 * Person slot — the free-text owner columns are gone.
 */
final class SchemaDrivenMapperOwnerTextTest extends TestCase
{
    private function mapper(User|Person|null $found): SchemaDrivenMapper
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);
        $resolver = $this->createStub(OwnerTextResolver::class);
        $resolver->method('find')->willReturn($found);

        $schema = new EntityImportSchema('Asset', Asset::class, [
            OwnerImportFields::text('owner', 'ownerUser', 'ownerPerson'),
        ]);

        return new SchemaDrivenMapper($this->createStub(EntityManagerInterface::class), $modules, $schema, $resolver);
    }

    #[Test]
    public function aKnownUserLandsInTheUserSlot(): void
    {
        $user = new User();
        $data = $this->mapper($user)->toEntityData(['owner' => 'Max Muster'], null, new Tenant());

        self::assertSame($user, $data['ownerUser']);
        self::assertNull($data['ownerPerson']);
        self::assertArrayNotHasKey('owner', $data);
    }

    #[Test]
    public function aKnownPersonLandsInThePersonSlot(): void
    {
        $person = (new Person())->setFullName('Max Muster');
        $data = $this->mapper($person)->toEntityData(['owner' => 'Max Muster'], null, new Tenant());

        self::assertNull($data['ownerUser']);
        self::assertSame($person, $data['ownerPerson']);
    }

    #[Test]
    public function anUnknownNameTravelsAsOwnerTextUntilCommit(): void
    {
        $data = $this->mapper(null)->toEntityData(['owner' => '  Neue Kollegin '], null, new Tenant());

        self::assertInstanceOf(OwnerText::class, $data['ownerPerson']);
        self::assertSame('Neue Kollegin', $data['ownerPerson']->text);
        self::assertSame('setOwnerUser', $data['ownerPerson']->userSetter);
        self::assertSame('setOwnerPerson', $data['ownerPerson']->personSetter);
        self::assertSame('Neue Kollegin', (string) $data['ownerPerson'], 'the preview shows the name');
    }

    #[Test]
    public function anEmptyOwnerCellSetsNothing(): void
    {
        self::assertSame([], $this->mapper(null)->toEntityData(['owner' => ''], null, new Tenant()));
    }
}
