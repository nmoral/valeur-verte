<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\Restitution;

/**
 * Bâtisseur d'instantané pour les tests : un contexte de rapport valide et
 * minimal, dont on ne surcharge que ce qui compte pour le cas testé.
 */
final class InstantaneRapportMother
{
    public static function creer(
        string $adresse = '12 rue des Écoles, Baziège (31)',
        Dpe $dpeAvant = Dpe::E,
        Dpe $dpeApres = Dpe::C,
        ?int $montantEuros = 52610,
    ): InstantaneRapport {
        $saisie = SaisieAudit::depuis($adresse, 90, 500, $dpeAvant, $dpeApres, 4);

        $restitution = null === $montantEuros ? null : Restitution::depuis(
            $montantEuros,
            (int) round($montantEuros * 0.85),
            (int) round($montantEuros * 1.15),
            134,
            10,
            2024,
            147150,
            false,
        );
        $contexte = null === $montantEuros ? null : ContexteCalcul::depuis(-0.20, 0.10, 17.22);

        return InstantaneRapport::creer(
            $saisie,
            $restitution,
            $contexte,
            Branding::parDefaut(),
            ReferenceRapport::depuis('VV-20260101-AAAAAA'),
            new \DateTimeImmutable('2026-08-01 09:00:00'),
        );
    }
}
