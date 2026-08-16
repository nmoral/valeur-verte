<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Assemble l'étude publiée à partir du modèle servi et des contours figés.
 *
 * Tant qu'aucun millésime n'a été publié à la main (REG-2), il n'y a pas d'étude :
 * mieux vaut pas de page qu'une page qui ment sur l'état du modèle.
 */
final readonly class EtudePubliee
{
    public function __construct(
        private LectureModelePublie $lecture,
        private ContoursDepartements $contours,
    ) {
    }

    public function courante(): ?Etude
    {
        $publication = $this->lecture->publication();
        if (null === $publication) {
            return null;
        }

        $metriques = MetriquesDepartementales::depuisMailles($this->lecture->mailles());

        return new Etude(
            millesime: $publication->millesime,
            publieLe: $publication->publieLe,
            calculeLe: $publication->calculeLe,
            zonesMesurees: $metriques->zonesMesurees(),
            departementsCouverts: $metriques->departementsCouverts(),
            communesReferencees: $this->lecture->communesReferencees(),
            medianeGainEc: $metriques->medianeGainEc(),
            medianeGainEd: $metriques->medianeGainEd(),
            carte: (new Carte($this->contours->chemins(), $metriques))->donnees(),
        );
    }
}
