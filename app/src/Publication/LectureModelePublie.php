<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Lecture du modèle **publié** (REG-2) pour la page publique : le millésime validé
 * à la main, jamais le dernier calculé.
 */
interface LectureModelePublie
{
    public function publication(): ?Publication;

    /**
     * @return list<Maille> mailles servies au public, triées par code INSEE
     */
    public function mailles(): array;

    public function communesReferencees(): int;
}
