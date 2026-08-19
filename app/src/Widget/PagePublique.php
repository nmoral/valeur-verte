<?php

declare(strict_types=1);

namespace App\Widget;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Page publique d'estimation hébergée d'un pro (#40, épic #6, décision D9).
 *
 * Servie sur le sous-domaine `widget.` en `/{token}/{slug}` : le `token` opaque
 * (non énumérable) porte l'unicité et désigne le tenant propriétaire, faute de
 * session sur une page publique ; le `slug` n'est qu'un habillage lisible de
 * l'URL, libre et non contraint. L'interrupteur `active` permet de la retirer
 * (#38) : une page désactivée n'existe plus pour le visiteur.
 *
 * La génération d'un token opaque et unique relève de #38 ; ici il est fourni.
 */
#[ORM\Entity(repositoryClass: PagePubliqueRepository::class)]
#[ORM\Table(name: 'page_publique')]
class PagePublique implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\Column(length: 120)]
    private string $slug;

    #[ORM\Column]
    private bool $active;

    private function __construct(int $tenantId, string $token, string $slug)
    {
        $this->setTenantId($tenantId);
        $this->token = $token;
        $this->slug = $slug;
        $this->active = true;
    }

    public static function creer(int $tenantId, string $token, string $slug): self
    {
        return new self($tenantId, $token, $slug);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function estActive(): bool
    {
        return $this->active;
    }

    public function desactiver(): void
    {
        $this->active = false;
    }

    /**
     * Tenant propriétaire, garanti non nul : une page ne se crée jamais sans
     * tenant (cf. {@see self::creer()}).
     */
    public function tenantIdProprietaire(): int
    {
        \assert(null !== $this->tenantId);

        return $this->tenantId;
    }
}
