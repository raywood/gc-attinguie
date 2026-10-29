<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Historique des actions administratives, sans mot de passe ni jeton d'invitation. */
#[ORM\Entity]
#[ORM\Table(name: 'admin_user_audit')]
#[ORM\Index(name: 'idx_admin_user_audit_target_date', columns: ['target_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_admin_user_audit_actor', columns: ['actor_user_id'])]
class AdminUserAudit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $target = null;

    #[ORM\Column(length: 40)]
    private string $action;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $details = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;
}
