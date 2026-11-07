<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Security\Persona\Persona;
use App\Security\Persona\PersonaResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who may download which report — decided by persona
 * ({@see Persona::reports()}), not per controller.
 *
 * Auditors get the audit package and the certification bundle themselves
 * (read-only exports); top management gets the board one-pager, review
 * output and trend report; every operational persona gets everything.
 */
final class ReportAccessVoter extends Voter
{
    public const string AUDIT_PACKAGE = 'REPORT_AUDIT_PACKAGE';
    public const string CERTIFICATION_BUNDLE = 'REPORT_CERTIFICATION_BUNDLE';
    public const string CERTIFICATION_READINESS = 'REPORT_CERTIFICATION_READINESS';
    public const string EVIDENCE_PACKAGE = 'REPORT_EVIDENCE_PACKAGE';
    public const string BOARD_ONE_PAGER = 'REPORT_BOARD_ONE_PAGER';
    public const string REVIEW_OUTPUT = 'REPORT_REVIEW_OUTPUT';
    public const string QUARTERLY_TREND = 'REPORT_QUARTERLY_TREND';

    private const array ATTRIBUTES = [
        self::AUDIT_PACKAGE,
        self::CERTIFICATION_BUNDLE,
        self::CERTIFICATION_READINESS,
        self::EVIDENCE_PACKAGE,
        self::BOARD_ONE_PAGER,
        self::REVIEW_OUTPUT,
        self::QUARTERLY_TREND,
    ];

    public function __construct(private readonly PersonaResolver $personaResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof UserInterface && $this->personaResolver->mayDownload($user, $attribute);
    }
}
