<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Paramètres d'identité de l'unique mairie pilote. Prépare une future portée par collectivité. */
#[ORM\Entity]
#[ORM\Table(name: 'app_branding')]
class AppBranding
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = 1;

    #[ORM\Column(name: 'app_name', length: 100)]
    private string $appName = 'GC-ATTINGUIÉ';

    #[ORM\Column(name: 'organization_name', length: 150)]
    private string $organizationName = 'Commune d’Attinguié';

    #[ORM\Column(name: 'dashboard_description', length: 500)]
    private string $dashboardDescription = 'La plateforme de gestion des commerces de la Commune d’Attinguié.';

    #[ORM\Column(name: 'logo_filename', length: 100, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by_id', nullable: true)]
    private ?int $updatedById = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): int { return $this->id; }
    public function getAppName(): string { return $this->appName; }
    public function getOrganizationName(): string { return $this->organizationName; }
    public function getDashboardDescription(): string { return $this->dashboardDescription; }
    public function getLogoFilename(): ?string { return $this->logoFilename; }
    public function getVersion(): int { return $this->version; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getUpdatedById(): ?int { return $this->updatedById; }

    public function setContent(string $appName, string $organizationName, string $dashboardDescription): void
    {
        $this->appName = $appName;
        $this->organizationName = $organizationName;
        $this->dashboardDescription = $dashboardDescription;
    }

    public function setLogoFilename(string $filename): void { $this->logoFilename = $filename; }

    public function touch(int $actorId): void
    {
        ++$this->version;
        $this->updatedById = $actorId;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'appName' => $this->appName,
            'organizationName' => $this->organizationName,
            'dashboardDescription' => $this->dashboardDescription,
            'logoFilename' => $this->logoFilename,
            'version' => $this->version,
        ];
    }
}
