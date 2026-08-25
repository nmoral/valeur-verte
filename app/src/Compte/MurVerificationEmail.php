<?php

declare(strict_types=1);

namespace App\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Security\User;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Mur de vérification d'email (#333) : au-delà du **premier** rapport, produire
 * exige une adresse **vérifiée** (#105). Le premier rapport reste libre — ONB-4
 * pose le droit à la saisie, il ne doit rien attendre —, mais un alias jetable
 * qu'on ne relève pas ne sert plus à rien passé ce point. On ne refuse pas
 * l'inscription : on adosse la **suite** du quota à la vérification.
 *
 * Chaque blocage est **mesuré une fois par pro** (drapeau idempotent sur le compte
 * + événement au journal d'audit) : le taux de pros bloqués se compte en acteurs
 * distincts, sans flux bruité par les rechargements de page.
 */
final readonly class MurVerificationEmail
{
    public function __construct(
        private CompteRepository $comptes,
        private CompteurRapportsConsommes $compteur,
        private TenantContext $tenant,
        private JournalAudit $journal,
        private ClockInterface $horloge,
    ) {
    }

    public function bloqueLaProduction(User $utilisateur): bool
    {
        if ($utilisateur->emailEstVerifie()) {
            return false;
        }

        $tenantId = $this->tenant->actuel();
        if (null === $tenantId) {
            return false;
        }

        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte || $this->compteur->pour($tenantId) < 1) {
            return false;
        }

        $this->mesurer($compte, $tenantId, $utilisateur);

        return true;
    }

    private function mesurer(Compte $compte, int $tenantId, User $utilisateur): void
    {
        if ($compte->blocageVerificationDejaSignale()) {
            return;
        }

        $compte->signalerBlocageVerification($this->horloge->now());
        $this->comptes->enregistrer($compte);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $utilisateur->getUserIdentifier(),
            action: 'rapport.verification_requise',
            cible: 'compte#'.$tenantId,
            contexte: ['tenant_id' => $tenantId],
            survenuLe: $this->horloge->now(),
        ));
    }
}
