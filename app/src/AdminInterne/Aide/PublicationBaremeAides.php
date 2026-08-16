<?php

declare(strict_types=1);

namespace App\AdminInterne\Aide;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\BaremeAidesRepository;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use Psr\Clock\ClockInterface;

/**
 * Publication d'une version du barème d'aides depuis l'admin (#26) : compose le
 * barème, le publie à sa date d'effet et trace le changement au journal d'audit
 * (#50). Un barème invalide est rejeté avant toute écriture — et donc sans trace,
 * puisqu'il ne s'est rien passé.
 */
final readonly class PublicationBaremeAides
{
    public function __construct(
        private BaremeAidesRepository $baremes,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, int> $tauxPourCent  indexé par CategorieRevenu->name
     * @param array<int, int>    $plafondsEuros plafonds de dépenses HT, par gain de classes
     */
    public function publier(
        \DateTimeImmutable $dateEffet,
        array $tauxPourCent,
        array $plafondsEuros,
        int $plafondEcoPtz,
        int $tauxTvaPourMille,
        string $auteur,
    ): void {
        $nouveau = BaremeAides::depuis(
            PeriodeValidite::depuis($dateEffet),
            $tauxPourCent,
            self::montants($plafondsEuros),
            self::montantPositif($plafondEcoPtz, 'Le plafond de l\'éco-PTZ'),
            $tauxTvaPourMille,
        );

        $ancien = $this->baremes->courant();

        $this->baremes->publier($nouveau);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'bareme_aides.publie',
            cible: 'bareme_aides',
            contexte: [
                'ancien' => self::resume($ancien),
                'nouveau' => self::resume($nouveau),
                'date_effet' => $dateEffet->format('Y-m-d'),
            ],
            survenuLe: $this->clock->now(),
        ));
    }

    /**
     * @param array<int, int> $plafondsEuros
     *
     * @return array<int, Montant>
     */
    private static function montants(array $plafondsEuros): array
    {
        $montants = [];
        foreach ($plafondsEuros as $gain => $euros) {
            $montants[$gain] = self::montantPositif(
                $euros,
                \sprintf('Le plafond de dépenses pour un gain de %d classe(s)', $gain),
            );
        }

        return $montants;
    }

    private static function montantPositif(int $euros, string $libelle): Montant
    {
        if ($euros < 0) {
            throw new \InvalidArgumentException(\sprintf('%s ne peut pas être négatif.', $libelle));
        }

        return Montant::fromEuros($euros);
    }

    /**
     * @return array<string, mixed>
     */
    private static function resume(BaremeAides $bareme): array
    {
        $taux = [];
        foreach (CategorieRevenu::tranches() as $categorie) {
            $taux[$categorie->name] = $bareme->tauxPourCent($categorie);
        }

        $plafonds = [];
        foreach ($bareme->gainsCouverts() as $gain) {
            $plafonds[$gain] = $bareme->plafondDepenses($gain)->euros();
        }

        return [
            'taux_pour_cent' => $taux,
            'plafonds_euros' => $plafonds,
            'plafond_eco_ptz' => $bareme->plafondEcoPtz()->euros(),
            'taux_tva_pour_mille' => $bareme->tauxTvaPourMille(),
        ];
    }
}
