<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Audit append-only de l'identité visuelle : ne stocke jamais le contenu des images. */
#[ORM\Entity]
#[ORM\Table(name: 'app_branding_audit')]
class AppBrandingAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'actor_user_id')]
    private int $actorUserId;

    #[ORM\Column(length: 32)]
    private string $action;

    /** @var array<string,mixed> */
    #[ORM\Column(name: 'before_state', type: Types::JSON, options: ['jsonb' => true])]
    private array $beforeState;

    /** @var array<string,mixed> */
    #[ORM\Column(name: 'after_state', type: Types::JSON, options: ['jsonb' => true])]
    private array $afterState;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function __construct(int $actorId, string $action, array $before, array $after)
    {
        $this->actorUserId = $actorId;
        $this->action = $action;
        $this->beforeState = $before;
        $this->afterState = $after;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
