<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

/**
 * Génère l'identifiant de rapport (§9.6) à partir de la date du jour et d'un
 * suffixe aléatoire, qui assure l'unicité entre deux rapports du même jour.
 */
final class ReferenceRapportAleatoire implements GenerateurReferenceRapport
{
    public function generer(): ReferenceRapport
    {
        $suffixe = strtoupper(bin2hex(random_bytes(3)));

        return ReferenceRapport::depuis(\sprintf('VV-%s-%s', date('Ymd'), $suffixe));
    }
}
