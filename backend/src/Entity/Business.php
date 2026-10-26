<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\BusinessStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'businesses')]
#[ORM\Index(name: 'idx_businesses_activity_type', columns: ['activity_type_id'])]
class Business
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ActivityType $activityType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $district = null;

    #[ORM\Column(length: 20, enumType: BusinessStatus::class)]
    private BusinessStatus $status = BusinessStatus::Active;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, BusinessManager> */
    #[ORM\OneToMany(mappedBy: 'business', targetEntity: BusinessManager::class, cascade: ['persist'])]
    private Collection $managers;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->managers = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getActivityType(): ActivityType { return $this->activityType; }
    public function setActivityType(ActivityType $v): static { $this->activityType = $v; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $v): static { $this->address = $v; return $this; }
    public function getDistrict(): ?string { return $this->district; }
    public function setDistrict(?string $v): static { $this->district = $v; return $this; }
    public function getStatus(): BusinessStatus { return $this->status; }
    public function setStatus(BusinessStatus $v): static { $this->status = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, BusinessManager> */
    public function getManagers(): Collection { return $this->managers; }
}
