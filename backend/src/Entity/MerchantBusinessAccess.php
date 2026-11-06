<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Rattachement explicite : une adresse e-mail de gérant ne constitue pas une autorisation. */
#[ORM\Entity]
#[ORM\Table(name: 'merchant_business_access')]
#[ORM\UniqueConstraint(name: 'UNIQ_GC_MERCHANT_ACCESS', columns: ['user_id', 'business_id'])]
class MerchantBusinessAccess
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Business::class)]
    #[ORM\JoinColumn(name: 'business_id', nullable: false, onDelete: 'RESTRICT')]
    private Business $business;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'granted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy;

    #[ORM\Column(name: 'granted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, Business $business, ?User $by)
    {
        $this->user = $user;
        $this->business = $business;
        $this->grant($by);
    }
    public function grant(?User $by): void
    {
        $this->grantedBy = $by;
        $this->grantedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
        $this->revokedAt = null;
    }
    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan'));
    }
    public function getUser(): User { return $this->user; }
    public function getBusiness(): Business { return $this->business; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getGrantedAt(): \DateTimeImmutable { return $this->grantedAt; }
}
