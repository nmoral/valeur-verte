<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\BackOffice\Aide\BaremeAidesRepository;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\GrilleCoutRepository;
use App\BackOffice\Pdf\GenerateurReferenceRapport;
use App\BackOffice\Restitution\Restitution;
use App\Compte\NotificationPremierRapport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Archive un audit terminé sous forme de rapport immuable (#33, #37) : dès qu'une
 * saisie valide produit un résultat — chiffré ou non — un instantané JSONB du
 * contexte est figé et persisté, si bien que l'audit apparaît aussitôt dans la
 * liste « Rapports & leads » et que son PDF se régénère à l'identique.
 */
final readonly class ArchiveurRapport
{
    public function __construct(
        private BrandingRepository $brandings,
        private GenerateurReferenceRapport $references,
        private RapportRepository $rapports,
        private UrlGeneratorInterface $urls,
        private NotificationPremierRapport $notificationPremierRapport,
        private GrilleCoutRepository $grilles,
        private BaremeAidesRepository $baremes,
    ) {
    }

    public function archiver(SaisieAudit $saisie, ?Restitution $restitution, ?ContexteCalcul $contexte, ?Bouquet $bouquet = null): Rapport
    {
        $reference = $this->references->generer();

        $instantane = InstantaneRapport::creer(
            $saisie,
            $restitution,
            $contexte,
            $this->brandings->courant(),
            $reference,
            new \DateTimeImmutable(),
            $bouquet,
            // Chiffrage et barème figés au moment de l'audit (#26, §9.1) : ni la
            // grille du pro ni le barème national ne pourront le faire bouger après
            // coup. Sans travaux retenus, il n'y a pas de coût à figer.
            null === $bouquet ? null : Chiffrage::derive(
                $bouquet,
                $this->grilles->courant(),
                QuantitesTravaux::depuis($saisie),
            ),
            null === $bouquet ? null : $this->baremes->courant(),
        );

        $rapport = Rapport::creer(
            $reference->valeur(),
            $instantane->genereLe(),
            Source::Audit,
            $instantane,
            $this->urls->generate('back_office_rapport_pdf', ['reference' => $reference->valeur()]),
        );
        $this->rapports->enregistrer($rapport);

        // Relance conversationnelle ONB-7 (#109) : déclenchée sur l'événement
        // « rapport archivé ». Une seule fois par compte, best-effort — le
        // service absorbe toute panne pour ne jamais casser l'archivage.
        $this->notificationPremierRapport->surTenant($rapport->getTenantId());

        return $rapport;
    }
}
