<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Détection des pics d'activité d'une page publique (#268, épic #6).
 *
 * Lit les événements du parcours (#90) sur la fenêtre glissante des seuils et
 * compare chaque motif au sien. Ne décide rien : il mesure et qualifie. Quand
 * plusieurs seuils sautent ensemble, c'est le dépassement le plus **ample**
 * (mesure rapportée au seuil) qui qualifie le pic — sinon l'ordre d'examen
 * déciderait à la place des faits.
 */
final readonly class DetecteurPicWidget
{
    public function __construct(
        private EvenementWidgetRepository $evenements,
        private SeuilsAntiAbusWidget $seuils,
    ) {
    }

    public function detecter(string $token, \DateTimeImmutable $maintenant): ?PicWidget
    {
        $comptes = $this->evenements->compterParTypeDepuis($token, $this->seuils->debutFenetre($maintenant));

        $pire = null;
        foreach (MotifPicWidget::cases() as $motif) {
            $pic = $this->pic($motif, $comptes);
            if (null !== $pic && (null === $pire || $pic->ampleur() > $pire->ampleur())) {
                $pire = $pic;
            }
        }

        return $pire;
    }

    /**
     * @param array<string, int> $comptes
     */
    private function pic(MotifPicWidget $motif, array $comptes): ?PicWidget
    {
        $seuil = $this->seuils->seuilDe($motif);
        $mesure = 0;
        foreach ($motif->typesMesures() as $type) {
            $mesure += $comptes[$type->value] ?? 0;
        }

        if ($mesure <= $seuil) {
            return null;
        }

        return new PicWidget($motif, $mesure, $seuil, $mesure >= $seuil * $this->seuils->facteurCritique());
    }
}
