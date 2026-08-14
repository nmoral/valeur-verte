<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Pdf\GenerateurReferenceRapport;
use App\BackOffice\Restitution\Restitution;
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
        );

        $rapport = Rapport::creer(
            $reference->valeur(),
            $instantane->genereLe(),
            Source::Audit,
            $instantane,
            $this->urls->generate('back_office_rapport_pdf', ['reference' => $reference->valeur()]),
        );
        $this->rapports->enregistrer($rapport);

        return $rapport;
    }
}
