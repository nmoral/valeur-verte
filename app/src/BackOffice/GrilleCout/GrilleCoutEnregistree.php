<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grille de coûts du pro persistée (#34), cloisonnée par tenant. Adaptateur entre
 * le value object {@see GrilleCout} — pur, versionné — et la base : montants en
 * JSONB (euros par nom de poste), période versionnée en colonnes (D4). Le domaine
 * ne dépend jamais de Doctrine.
 */
#[ORM\Entity(repositoryClass: GrilleCoutRepository::class)]
#[ORM\Table(name: 'grille_cout')]
class GrilleCoutEnregistree implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $valideDu;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideAu;

    /**
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $montants;

    private function __construct(GrilleCout $grille)
    {
        $periode = $grille->periode();
        $this->valideDu = $periode->valideDu();
        $this->valideAu = $periode->valideAu();

        $this->montants = [];
        foreach (Poste::cases() as $poste) {
            $this->montants[$poste->name] = $grille->montant($poste)->euros();
        }
    }

    public static function depuis(GrilleCout $grille): self
    {
        return new self($grille);
    }

    public function versGrille(): GrilleCout
    {
        $periode = PeriodeValidite::depuis($this->valideDu);
        if (null !== $this->valideAu) {
            $periode = $periode->fermerLe($this->valideAu);
        }

        $montants = [];
        foreach ($this->montants as $poste => $euros) {
            $montants[$poste] = Montant::fromEuros($euros);
        }

        return GrilleCout::depuis($periode, $montants);
    }
}
