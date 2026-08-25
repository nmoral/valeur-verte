<?php

declare(strict_types=1);

namespace App\Publication;

use App\BackOffice\Restitution\IndicateurFiabilite;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;

/**
 * Objet de présentation publique d'une commune (#360, SEO Vague 2 #338) : agrège
 * la maille publiée en chiffres prêts pour la page SEO — décote/surcote par
 * étiquette, prix m² par classe, gains de cran, effectif, portée et indicateur de
 * fiabilité. Analogue commune de {@see FicheDepartement}.
 *
 * Aucun recalcul métier : les gains et la décote/surcote délèguent à {@see Maille}
 * (effets log de base D, lisibles en ``exp(Δ) − 1``), la fiabilité aux enums
 * {@see IndicateurFiabilite}/{@see PorteeEstimation}. Le prix par classe applique
 * l'effet log de la classe à la médiane du prix au m² de la commune.
 */
final readonly class FicheCommune
{
    /** Ordre d'affichage des étiquettes, de la meilleure à la pire. */
    private const ORDRE_CLASSES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    private function __construct(
        public string $codeInsee,
        public string $nom,
        public int $millesime,
        public ?\DateTimeImmutable $calculeLe,
        private Maille $maille,
        public int $n,
        public ?int $rayonKm,
        public float $erreurMediane,
        private ?float $prixM2Median,
        public PalierFiabilite $palier,
    ) {
    }

    public static function depuis(
        string $codeInsee,
        string $nom,
        int $millesime,
        ?\DateTimeImmutable $calculeLe,
        Maille $maille,
        int $n,
        ?int $rayonKm,
        float $erreurMediane,
        ?float $prixM2Median,
        PalierFiabilite $palier,
    ): self {
        return new self(
            $codeInsee,
            $nom,
            $millesime,
            $calculeLe,
            $maille,
            $n,
            $rayonKm,
            $erreurMediane,
            $prixM2Median,
            $palier,
        );
    }

    public function indicateur(): IndicateurFiabilite
    {
        return IndicateurFiabilite::depuis($this->n, $this->rayonKm);
    }

    public function portee(): PorteeEstimation
    {
        return PorteeEstimation::depuis($this->maille->mode, $this->rayonKm);
    }

    public function gainEc(): ?int
    {
        return $this->maille->gain('E', 'C');
    }

    public function gainCb(): ?int
    {
        return $this->maille->gain('C', 'B');
    }

    /**
     * Décote (−) / surcote (+) en pourcentage de chaque étiquette publiée, base D,
     * ordonnée de A vers G.
     *
     * @return array<string, int>
     */
    public function decoteSurcote(): array
    {
        $parClasse = [];
        foreach ($this->classesPubliees() as $classe) {
            $ecart = $this->maille->gain('D', $classe);
            if (null !== $ecart) {
                $parClasse[$classe] = $ecart;
            }
        }

        return $parClasse;
    }

    public function prixM2Median(): ?int
    {
        return null === $this->prixM2Median ? null : (int) round($this->prixM2Median);
    }

    /**
     * Prix au m² estimé par étiquette : la médiane communale pondérée par l'effet
     * log de la classe. Vide quand aucun prix de marché n'est disponible.
     *
     * @return array<string, int>
     */
    public function prixM2ParClasse(): array
    {
        if (null === $this->prixM2Median) {
            return [];
        }

        $parClasse = [];
        foreach ($this->classesPubliees() as $classe) {
            $parClasse[$classe] = (int) round($this->prixM2Median * exp($this->maille->coefDpe[$classe]));
        }

        return $parClasse;
    }

    /**
     * Les étiquettes publiées sur la maille, ordonnées de A vers G.
     *
     * @return list<string>
     */
    private function classesPubliees(): array
    {
        return array_values(array_filter(
            self::ORDRE_CLASSES,
            fn (string $classe): bool => \array_key_exists($classe, $this->maille->coefDpe),
        ));
    }
}
