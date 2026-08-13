<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

/**
 * Fabrique d'identifiants de rapport uniques (§9.6). Abstraction pour découpler
 * la génération du PDF de la stratégie d'unicité (aléatoire, séquentielle, …).
 */
interface GenerateurReferenceRapport
{
    public function generer(): ReferenceRapport;
}
