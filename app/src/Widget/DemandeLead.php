<?php

declare(strict_types=1);

namespace App\Widget;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de lead « à confirmer » (#42, épic #6, décision D9).
 *
 * Émise quand un visiteur du widget laisse ses coordonnées après le premier
 * chiffre : elle porte l'**instantané figé** de l'estimation (ce qu'il a vu), son
 * contact et son consentement horodaté, mais **n'est pas encore un Rapport** —
 * le pro n'en voit rien tant que l'email n'est pas confirmé. Le jeton de
 * confirmation est **à usage unique et expirant** ; seule son empreinte SHA-256
 * est stockée (pattern #194), le brut ne vit que dans le lien de l'email.
 */
#[ORM\Entity(repositoryClass: DemandeLeadRepository::class)]
#[ORM\Table(name: 'demande_lead')]
#[ORM\UniqueConstraint(name: 'uniq_demande_lead_empreinte', columns: ['empreinte'])]
class DemandeLead implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    #[ORM\Column(length: 255)]
    private string $contactNom;

    #[ORM\Column(length: 255)]
    private string $contactEmail;

    /**
     * Instantané figé de l'estimation (#33), sérialisé en JSONB : le PDF du lead
     * confirmé en est régénéré à l'identique, jamais recalculé.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $instantane;

    #[ORM\Column]
    private \DateTimeImmutable $consentementLe;

    /**
     * @param array<string, mixed> $instantane
     */
    private function __construct(
        int $tenantId,
        string $empreinte,
        \DateTimeImmutable $expireLe,
        string $contactNom,
        string $contactEmail,
        array $instantane,
        \DateTimeImmutable $consentementLe,
    ) {
        $this->setTenantId($tenantId);
        $this->empreinte = $empreinte;
        $this->expireLe = $expireLe;
        $this->contactNom = $contactNom;
        $this->contactEmail = $contactEmail;
        $this->instantane = $instantane;
        $this->consentementLe = $consentementLe;
    }

    /**
     * @param array<string, mixed> $instantane
     */
    public static function creer(
        int $tenantId,
        string $empreinte,
        \DateTimeImmutable $expireLe,
        string $contactNom,
        string $contactEmail,
        array $instantane,
        \DateTimeImmutable $consentementLe,
    ): self {
        return new self($tenantId, $empreinte, $expireLe, $contactNom, $contactEmail, $instantane, $consentementLe);
    }

    public function estUtilisable(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->utiliseLe && $maintenant < $this->expireLe;
    }

    public function marquerUtilise(\DateTimeImmutable $maintenant): void
    {
        $this->utiliseLe = $maintenant;
    }

    public function contactNom(): string
    {
        return $this->contactNom;
    }

    public function contactEmail(): string
    {
        return $this->contactEmail;
    }

    /**
     * @return array<string, mixed>
     */
    public function instantane(): array
    {
        return $this->instantane;
    }

    public function tenantIdProprietaire(): int
    {
        \assert(null !== $this->tenantId);

        return $this->tenantId;
    }
}
