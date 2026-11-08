<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\Responsibility\ResponsibilityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** "Is this the one who has to act on the record?" — whatever ResponsibilityResolver answers, for active accounts only. */
final class RecordResponsibleVoterTest extends TestCase
{
    #[Test]
    public function grantsWhomTheResolverNamesAndNobodyElse(): void
    {
        $responsible = $this->user(active: true);
        $other = $this->user(active: true);
        $record = new Risk();
        $voter = $this->voter(static fn (User $u): bool => $u === $responsible);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($responsible), $record, [RecordResponsibleVoter::RESPONSIBLE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($other), $record, [RecordResponsibleVoter::RESPONSIBLE]));
    }

    #[Test]
    public function aDeactivatedAccountIsNeverResponsible(): void
    {
        $user = $this->user(active: false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter(static fn (): bool => true)->vote($this->token($user), new Risk(), [RecordResponsibleVoter::RESPONSIBLE]),
        );
    }

    #[Test]
    public function abstainsForOtherAttributesAndWithoutRecord(): void
    {
        $voter = $this->voter(static fn (): bool => true);
        $token = $this->token($this->user(active: true));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new Risk(), ['edit']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [RecordResponsibleVoter::RESPONSIBLE]));
    }

    private function voter(\Closure $isResponsible): RecordResponsibleVoter
    {
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('isResponsibleFor')->willReturnCallback($isResponsible);

        return new RecordResponsibleVoter($resolver);
    }

    private function user(bool $active): User
    {
        $user = $this->createStub(User::class);
        $user->method('isActive')->willReturn($active);
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
