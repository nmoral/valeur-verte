<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Version persistée du barème d'aides (#26). Adaptateur entre l'objet-valeur
 * {@see BaremeAides} — pur, versionné — et la base : taux et plafonds en JSONB,
 * période versionnée en colonnes (décision D4). Le domaine ne dépend pas de Doctrine.
 *
 * Le barème est **national**, donc sans cloisonnement par tenant : il est maintenu
 * par nous, contrairement à la grille de coûts du pro (#34).
 */
#[ORM\Entity(repositoryClass: BaremeAidesRepository::class)]
#[ORM\Table(name: 'bareme_aides')]
#[ORM\Index(name: 'idx_bareme_aides_valide_du', columns: ['valide_du'])]
class BaremeAidesEnregistre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'valide_du', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $valideDu;

    #[ORM\Column(name: 'valide_au', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valideAu = null;

    /**
     * @var array<string, int> taux en pourcentage, indexé par CategorieRevenu->name
     */
    #[ORM\Column(name: 'taux_pour_cent', type: Types::JSON, options: ['jsonb' => true])]
    private array $tauxPourCent;

    /**
     * @var array<int|string, int> plafonds de dépenses HT en euros, indexé par gain de classes
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $plafonds;

    #[ORM\Column(name: 'plafond_eco_ptz')]
    private int $plafondEcoPtz;

    #[ORM\Column(name: 'taux_tva_pour_mille')]
    private int $tauxTvaPourMille;

    private function __construct(BaremeAides $bareme)
    {
        $periode = $bareme->periode();
        $this->valideDu = $periode->valideDu();
        $this->valideAu = $periode->valideAu();

        $this->tauxPourCent = [];
        foreach (CategorieRevenu::tranches() as $categorie) {
            $this->tauxPourCent[$categorie->name] = $bareme->tauxPourCent($categorie);
        }

        $this->plafonds = [];
        foreach ($bareme->gainsCouverts() as $gain) {
            $this->plafonds[$gain] = $bareme->plafondDepenses($gain)->euros();
        }

        $this->plafondEcoPtz = $bareme->plafondEcoPtz()->euros();
        $this->tauxTvaPourMille = $bareme->tauxTvaPourMille();
    }

    public static function depuis(BaremeAides $bareme): self
    {
        return new self($bareme);
    }

    /**
     * Ferme la période de validité. Les taux et plafonds ne sont jamais touchés :
     * un rapport émis sous cette version doit continuer à les lire (§7).
     */
    public function cloturerLe(\DateTimeImmutable $valideAu): void
    {
        $this->valideAu = PeriodeValidite::depuis($this->valideDu)->fermerLe($valideAu)->valideAu();
    }

    public function versBareme(): BaremeAides
    {
        $periode = PeriodeValidite::depuis($this->valideDu);
        if (null !== $this->valideAu) {
            $periode = $periode->fermerLe($this->valideAu);
        }

        $plafonds = [];
        foreach ($this->plafonds as $gain => $euros) {
            $plafonds[(int) $gain] = Montant::fromEuros($euros);
        }

        return BaremeAides::depuis(
            $periode,
            $this->tauxPourCent,
            $plafonds,
            Montant::fromEuros($this->plafondEcoPtz),
            $this->tauxTvaPourMille,
        );
    }

    public function valideDu(): \DateTimeImmutable
    {
        return $this->valideDu;
    }
}
