<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Restitution\Restitution;
use Twig\Environment;

/**
 * Génère le PDF d'un rapport de valeur verte à l'en-tête du pro (#32, §4/§10.4).
 * Le document reprend la saisie auditée, la restitution (n, rayon, millésime) et,
 * le cas échéant, le bandeau d'alerte du mode dégradé (§5.3). L'identifiant de
 * rapport (§9.6) figure dans le document. Le contenu est rendu par Twig puis
 * confié au port de conversion — le calcul n'est jamais refait ici.
 */
final readonly class GenerateurPdfRapport
{
    public function __construct(
        private Environment $twig,
        private ConvertisseurPdf $convertisseur,
    ) {
    }

    public function generer(
        SaisieAudit $saisie,
        ?Restitution $restitution,
        Branding $branding,
        ReferenceRapport $reference,
    ): string {
        $html = $this->twig->render('back_office/pdf/rapport.html.twig', [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'branding' => $branding,
            'reference' => $reference->valeur(),
            'avertissements' => $saisie->avertissements(),
        ]);

        return $this->convertisseur->depuisHtml($html);
    }
}
