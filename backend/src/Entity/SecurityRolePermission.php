<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'security_role_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_security_role_permissions_pair', columns: ['role_id', 'permission_id'])]
#[ORM\Index(name: 'idx_security_role_perm_role', columns: ['role_id'])]
#[ORM\Index(name: 'idx_security_role_perm_permission', columns: ['permission_id'])]
class SecurityRolePermission
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SecurityRole::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SecurityRole $role;

    #[ORM\ManyToOne(targetEntity: SecurityPermission::class)]
    #[ORM\JoinColumn(name: 'permission_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SecurityPermission $permission;
}
