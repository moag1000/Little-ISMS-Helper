<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IctProviderLibraryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F-NEU — curated library of common ICT third-party providers (DORA Art. 28).
 *
 * Global catalogue (tenant_id NULL), seeded via {@see \App\Command\LoadIctProviderLibraryCommand},
 * mirroring the IndustryBaseline pattern. A tenant can "apply" a library entry to
 * pre-fill a {@see Supplier} for its Register of Information rather than typing
 * the master data by hand.
 *
 * Only generic, publicly-known provider metadata (name, category, HQ country,
 * typical service type) is shipped — no contractual or tenant-specific data.
 */
#[ORM\Entity(repositoryClass: IctProviderLibraryRepository::class)]
#[ORM\Table(name: 'ict_provider_library')]
#[ORM\UniqueConstraint(name: 'uniq_ict_provider_code', columns: ['code'])]
class IctProviderLibrary
{
    public const string CATEGORY_CLOUD_IAAS = 'cloud_iaas';
    public const string CATEGORY_CLOUD_SAAS = 'cloud_saas';
    public const string CATEGORY_NETWORK = 'network';
    public const string CATEGORY_IDENTITY = 'identity';
    public const string CATEGORY_DATA = 'data';
    public const string CATEGORY_SECURITY = 'security';
    public const string CATEGORY_PAYMENT = 'payment';
    public const string CATEGORY_COMMS = 'communications';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 30)]
    private string $category = self::CATEGORY_CLOUD_SAAS;

    /** ISO 3166-1 alpha-2 country of the provider's head office. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $headquartersCountry = null;

    /** Typical service description (free text). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $serviceType = null;

    /** Default criticality hint (critical|important|standard). */
    #[ORM\Column(length: 20)]
    private string $defaultCriticality = 'important';

    /** Whether the provider is commonly EEA-hosted (data-location hint). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $eeaHosted = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getHeadquartersCountry(): ?string
    {
        return $this->headquartersCountry;
    }

    public function setHeadquartersCountry(?string $headquartersCountry): static
    {
        $this->headquartersCountry = $headquartersCountry;
        return $this;
    }

    public function getServiceType(): ?string
    {
        return $this->serviceType;
    }

    public function setServiceType(?string $serviceType): static
    {
        $this->serviceType = $serviceType;
        return $this;
    }

    public function getDefaultCriticality(): string
    {
        return $this->defaultCriticality;
    }

    public function setDefaultCriticality(string $defaultCriticality): static
    {
        $this->defaultCriticality = $defaultCriticality;
        return $this;
    }

    public function isEeaHosted(): bool
    {
        return $this->eeaHosted;
    }

    public function setEeaHosted(bool $eeaHosted): static
    {
        $this->eeaHosted = $eeaHosted;
        return $this;
    }
}
