<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Espace cloisonné d'un pro. Son seul rôle au MVP est de frapper un identifiant
 * unique (`tenant_id`) à la création d'un compte ; les données pro le portent.
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
