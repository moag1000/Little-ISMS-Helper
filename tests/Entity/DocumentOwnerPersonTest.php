<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\Person;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase A — Document gains an `ownerPerson` Person FK
 * alongside the existing `uploadedBy` User FK. The legacy User stays
 * (audit-trail of the upload action) and `getEffectiveOwnerName()`
 * prefers the new Person, falling back to the uploader User.
 */
final class DocumentOwnerPersonTest extends TestCase
{
    #[Test]
    public function defaultsToUploaderWhenNoOwnerPersonSet(): void
    {
        $uploader = new User();
        $uploader->setFirstName('Up');
        $uploader->setLastName('Loader');

        $doc = new Document();
        $doc->setUploadedBy($uploader);

        self::assertNull($doc->getOwnerPerson());
        self::assertSame('Up Loader', $doc->getEffectiveOwnerName());
    }

    #[Test]
    public function ownerPersonOverridesUploaderInEffectiveName(): void
    {
        $uploader = new User();
        $uploader->setFirstName('Up');
        $uploader->setLastName('Loader');

        $owner = new Person();
        $owner->setFullName('External CISO');

        $doc = new Document();
        $doc->setUploadedBy($uploader);
        $doc->setOwnerPerson($owner);

        self::assertSame($owner, $doc->getOwnerPerson());
        self::assertSame('External CISO', $doc->getEffectiveOwnerName());
    }

    #[Test]
    public function returnsNullWhenNeitherSet(): void
    {
        $doc = new Document();

        self::assertNull($doc->getEffectiveOwnerName());
    }
}
