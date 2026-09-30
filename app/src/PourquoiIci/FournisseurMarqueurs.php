<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Port de lecture des marqueurs territoriaux (#341, Lot 4).
 *
 * Sépare le moteur de narration de la source des marqueurs (`data.marqueurs`,
 * lue seule par l'app). Deux portées : la commune (ses marqueurs communaux + ceux
 * de son département) et le département seul.
 */
interface FournisseurMarqueurs
{
    public function pourCommune(string $codeInsee, string $codeDepartement): Marqueurs;

    public function pourDepartement(string $codeDepartement): Marqueurs;
}
