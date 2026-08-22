<?php

declare(strict_types=1);

namespace App\Widget;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
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
 *
 * Deux verrous indépendants la retirent au visiteur, et il faut les deux ouverts
 * pour qu'elle soit servie : `active`, l'interrupteur du **pro** (#38), et
 * `suspendueLe`, le coup d'arrêt de l'**admin interne** ou de l'escalade
 * automatique anti-abus (#268). Les séparer garantit qu'un pro ne lève pas la
 * suspension prononcée contre sa page en rebasculant son propre interrupteur.
 */
#[ORM\Entity(repositoryClass: PagePubliqueRepository::class)]
#[ORM\Table(name: 'page_publique')]
class PagePublique implements TenantAwareInterface
{
    use TenantAwareTrait;

    /**
     * Borne du slug, celle de la colonne : une saisie plus longue est refusée au
     * bord (#38) plutôt que tranchée par une erreur SQL à l'insertion.
     */
    public const int LONGUEUR_MAX_SLUG = 120;

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

    /**
     * Date de suspension par l'admin interne ou par l'escalade anti-abus (#268).
     * Nulle = pas de suspension ; renseignée, la page ne se résout plus.
     */
    #[ORM\Column(name: 'suspendue_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendueLe = null;

    /**
     * Durcissement temporaire du défi anti-bot après un pic (#268) : jusqu'à cette
     * date, le Turnstile est rendu visible plutôt qu'invisible. Borné dans le temps
     * — la friction ne doit pas survivre à l'incident qui l'a justifiée.
     */
    #[ORM\Column(name: 'challenge_renforce_jusqua', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $challengeRenforceJusqua = null;

    /**
     * Dernière alerte de pic émise pour cette page (#268), qui porte la fenêtre de
     * silence : un pic dure, l'alerte ne doit pas se répéter à chaque requête.
     */
    #[ORM\Column(name: 'alertee_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $alerteeLe = null;

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

    /**
     * Renommer n'est pas déménager : le slug change, le jeton reste — les liens
     * déjà partagés continuent de résoudre la page (#40, le slug est décoratif).
     */
    public function renommer(SlugPage $slug): void
    {
        $this->slug = $slug->valeur();
    }

    public function estActive(): bool
    {
        return $this->active;
    }

    public function desactiver(): void
    {
        $this->active = false;
    }

    public function estSuspendue(): bool
    {
        return null !== $this->suspendueLe;
    }

    public function suspendre(\DateTimeImmutable $quand): void
    {
        $this->suspendueLe = $quand;
    }

    public function reactiver(): void
    {
        $this->suspendueLe = null;
    }

    public function renforcerChallengeJusqua(\DateTimeImmutable $echeance): void
    {
        $this->challengeRenforceJusqua = $echeance;
    }

    /**
     * Vrai tant que le durcissement décidé après un pic court encore (#268).
     */
    public function exigeChallengeVisible(\DateTimeImmutable $maintenant): bool
    {
        return null !== $this->challengeRenforceJusqua && $this->challengeRenforceJusqua > $maintenant;
    }

    public function marquerAlertee(\DateTimeImmutable $quand): void
    {
        $this->alerteeLe = $quand;
    }

    public function derniereAlerteLe(): ?\DateTimeImmutable
    {
        return $this->alerteeLe;
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
