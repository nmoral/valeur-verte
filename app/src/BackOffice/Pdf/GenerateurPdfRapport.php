<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\AjustementProfessionnel;
use App\BackOffice\Restitution\DeclarationsPro;
use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\ResteACharge;
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
    public function depuisInstantane(
        InstantaneRapport $instantane,
        ?AjustementProfessionnel $ajustement = null,
        ?DeclarationsPro $declarations = null,
    ): string {
        return $this->generer(
            $instantane->saisie(),
            $instantane->restitution(),
            $instantane->branding(),
            $instantane->reference(),
            $ajustement,
            $instantane->bouquet(),
            self::resteACharge($instantane, $declarations ?? DeclarationsPro::aucune()),
            $instantane->effetDeSeuil(),
        );
    }

    /**
     * Reste à charge reconstruit depuis l'instantané figé et les déclarations du
     * pro (#26). Rien n'est recalculé : le chiffrage et le barème sont ceux du jour
     * de l'audit, seules les déclarations viennent du présent — ce sont elles que
     * le pro peut corriger.
     */
    public static function resteACharge(InstantaneRapport $instantane, DeclarationsPro $declarations): ?ResteACharge
    {
        $chiffrage = $instantane->chiffrage();
        $bareme = $instantane->baremeAides();
        if (null === $chiffrage || null === $bareme) {
            return null;
        }

        $saisie = $instantane->saisie();

        return ResteACharge::calculer(
            $declarations->appliquerA($chiffrage),
            $saisie->dpeAvant()->classesGagneesVers($saisie->dpeApres()),
            $declarations->categorieRevenu(),
            $bareme,
        );
    }

    public function generer(
        SaisieAudit $saisie,
        ?Restitution $restitution,
        Branding $branding,
        ReferenceRapport $reference,
        ?AjustementProfessionnel $ajustement = null,
        ?Bouquet $bouquet = null,
        ?ResteACharge $resteACharge = null,
        ?EffetDeSeuil $effetDeSeuil = null,
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
            'reste_a_charge' => $resteACharge,
            'effet_de_seuil' => $effetDeSeuil,
        ]);

        return $this->convertisseur->depuisHtml($html);
    }
}
