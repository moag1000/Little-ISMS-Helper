<?php

declare(strict_types=1);

namespace App\Entity\Trait;

/**
 * Accessors of a processing activity's third-country transfer, retention and technical-organisational measures
 * (Art. 30(1)(e)-(g) GDPR). Extracted from ProcessingActivity to keep the entity within its god-class budget;
 * the properties stay on the entity (their mapping and API groups are read there).
 */
trait ProcessingActivityTransferRetentionTrait
{
    public function getHasThirdCountryTransfer(): bool
    {
        return $this->hasThirdCountryTransfer;
    }

    public function setHasThirdCountryTransfer(bool $hasThirdCountryTransfer): static
    {
        $this->hasThirdCountryTransfer = $hasThirdCountryTransfer;
        return $this;
    }

    public function getThirdCountries(): ?array
    {
        return $this->thirdCountries;
    }

    public function setThirdCountries(?array $thirdCountries): static
    {
        $this->thirdCountries = $thirdCountries;
        return $this;
    }

    public function getTransferSafeguards(): ?string
    {
        return $this->transferSafeguards;
    }

    public function setTransferSafeguards(?string $transferSafeguards): static
    {
        $this->transferSafeguards = $transferSafeguards;
        return $this;
    }

    public function getRetentionPeriod(): ?string
    {
        return $this->retentionPeriod;
    }

    public function setRetentionPeriod(?string $retentionPeriod): static
    {
        $this->retentionPeriod = $retentionPeriod;
        return $this;
    }

    public function getRetentionPeriodDays(): ?int
    {
        return $this->retentionPeriodDays;
    }

    public function setRetentionPeriodDays(?int $retentionPeriodDays): static
    {
        $this->retentionPeriodDays = $retentionPeriodDays;
        return $this;
    }

    public function getRetentionLegalBasis(): ?string
    {
        return $this->retentionLegalBasis;
    }

    public function setRetentionLegalBasis(?string $retentionLegalBasis): static
    {
        $this->retentionLegalBasis = $retentionLegalBasis;
        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getRetentionPerCategory(): ?array
    {
        return $this->retentionPerCategory;
    }

    /**
     * @param array<string, string>|null $retentionPerCategory
     */
    public function setRetentionPerCategory(?array $retentionPerCategory): static
    {
        $this->retentionPerCategory = $retentionPerCategory;
        return $this;
    }

    public function getTechnicalOrganizationalMeasures(): ?string
    {
        return $this->technicalOrganizationalMeasures;
    }

    public function setTechnicalOrganizationalMeasures(?string $technicalOrganizationalMeasures): static
    {
        $this->technicalOrganizationalMeasures = $technicalOrganizationalMeasures;
        return $this;
    }
}
