<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\Clock\ClockInterface;

/**
 * Journal du parcours widget (#90, épic #6) : la brique de collecte.
 *
 * Enregistre une étape franchie sur une page publique, rattachée à la page, à la
 * visite (identifiant anonyme) et au tenant propriétaire. Les restitutions (funnel
 * du pro #5, analytics interne #7) se construisent plus tard sur ces événements, et
 * l'abandon se lit comme l'absence de l'étape suivante.
 *
 * C'est aussi le point de passage unique de l'anti-abus réactif (#268) : une étape
 * qu'un motif de pic mesure déclenche l'évaluation dans la foulée. Le brancher ici
 * plutôt que dans les contrôleurs garantit qu'aucune source d'événements n'échappe
 * à la surveillance ; les autres étapes ne coûtent aucune requête d'analyse.
 */
final readonly class JournalParcoursWidget
{
    public function __construct(
        private EvenementWidgetRepository $evenements,
        private ClockInterface $horloge,
        private SurveillanceAntiAbusWidget $surveillance,
    ) {
    }

    /**
     * @param array<string, scalar|null> $donnees charge utile minimale, non personnelle
     */
    public function enregistrer(
        PagePublique $page,
        string $sessionId,
        TypeEvenementWidget $type,
        array $donnees = [],
    ): void {
        $this->evenements->enregistrer(EvenementWidget::survenu(
            $page->tenantIdProprietaire(),
            $page->token(),
            $sessionId,
            $type,
            $this->horloge->now(),
            $donnees,
        ));

        if ($type->declencheSurveillance()) {
            $this->surveillance->evaluer($page);
        }
    }
}
