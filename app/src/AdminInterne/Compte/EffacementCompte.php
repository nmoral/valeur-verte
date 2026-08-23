<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\BackOffice\Entreprise\EntrepriseRepository;
use App\BackOffice\Rapport\RapportRepository;
use App\Compte\CompteRepository;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Widget\DemandeLeadRepository;
use Psr\Clock\ClockInterface;

/**
 * Effacement définitif d'un compte (#308, RGPD droit à l'effacement). Supprime
 * tout le périmètre d'un tenant — rapports, leads, fiche entreprise,
 * utilisateur(s), compte et espace — et trace l'acte au journal d'audit, qui
 * n'est jamais effacé (table séparée, sans lien au tenant : la suppression n'y
 * cascade pas). Choix produit assumé (#308) : les rapports et leads partent
 * **avec** le compte, sans attendre le terme de leur rétention propre (D10).
 *
 * L'effacement n'intervient qu'après le **délai de grâce** ouvert par la demande
 * (AdministrationComptes::demanderSuppression) : une suppression accidentelle ne
 * se rattrape pas. Le finaliseur (cron) balaie les comptes exigibles.
 */
final readonly class EffacementCompte
{
    public function __construct(
        private RapportRepository $rapports,
        private DemandeLeadRepository $leads,
        private EntrepriseRepository $entreprises,
        private UserRepository $utilisateurs,
        private CompteRepository $comptes,
        private TenantRepository $tenants,
        private JournalAudit $journal,
        private ClockInterface $clock,
        private int $delaiDeGraceJours,
    ) {
    }

    /**
     * Finaliseur de grâce (cron) : efface tout compte dont la demande a dépassé
     * le délai de grâce (`delaiDeGraceJours`). Idempotent et rejouable — un compte
     * déjà effacé n'apparaît plus comme exigible. Renvoie le bilan du passage.
     */
    public function effacerLesComptesExigibles(string $auteur): BilanEffacement
    {
        $borne = $this->clock->now()->sub(new \DateInterval('P'.$this->delaiDeGraceJours.'D'));
        $exigibles = $this->comptes->exigiblesPourEffacement($borne);

        foreach ($exigibles as $compte) {
            $this->effacer($compte->tenantId(), $auteur);
        }

        return new BilanEffacement(\count($exigibles));
    }

    /**
     * Efface ici et maintenant tout le périmètre d'un tenant, puis trace l'acte.
     * Idempotent : sur un tenant déjà vide, chaque suppression compte 0 et la
     * trace n'en est pas moins écrite (l'acte a bien été demandé).
     */
    public function effacer(int $tenantId, string $auteur): void
    {
        $rapports = $this->rapports->supprimerParTenant($tenantId);
        $leads = $this->leads->supprimerParTenant($tenantId);
        $this->entreprises->supprimerParTenant($tenantId);
        $this->utilisateurs->supprimerParTenant($tenantId);
        $this->comptes->supprimerParTenant($tenantId);
        $this->tenants->supprimerParId($tenantId);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'compte.efface',
            cible: 'compte#'.$tenantId,
            contexte: ['tenant_id' => $tenantId, 'rapports_supprimes' => $rapports, 'leads_supprimes' => $leads],
            survenuLe: $this->clock->now(),
        ));
    }
}
