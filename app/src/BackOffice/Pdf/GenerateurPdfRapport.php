<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\AjustementProfessionnel;
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

    /**
     * Régénère le PDF depuis l'instantané immuable (#33) : reproductibilité à
     * l'identique, sans jamais recalculer.
     */
    public function depuisInstantane(InstantaneRapport $instantane, ?AjustementProfessionnel $ajustement = null): string
    {
        return $this->generer(
            $instantane->saisie(),
            $instantane->restitution(),
            $instantane->branding(),
            $instantane->reference(),
            $ajustement,
            $instantane->bouquet(),
        );
    }

    public function generer(
        SaisieAudit $saisie,
        ?Restitution $restitution,
        Branding $branding,
        ReferenceRapport $reference,
        ?AjustementProfessionnel $ajustement = null,
        ?Bouquet $bouquet = null,
    ): string {
        $html = $this->twig->render('back_office/pdf/rapport.html.twig', [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'branding' => $branding,
            'reference' => $reference->valeur(),
            'avertissements' => $saisie->avertissements(),
            // L'ajustement du pro (#30) n'a de sens qu'adossé à une mesure.
            'ajustement' => null !== $restitution ? $ajustement : null,
            'bouquet' => $bouquet,
        ]);

        return $this->convertisseur->depuisHtml($html);
    }
}
