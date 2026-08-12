<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Port : récupère les qualifications RGE d'un établissement (données ouvertes
 * ADEME). Ne renvoie jamais null : l'absence de réponse donne un statut
 * « Indéterminé », l'absence de ligne un statut « Non RGE ».
 */
interface QualificationsRge
{
    public function pourSiret(Siret $siret): QualificationRge;
}
