<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Compte\CompteRepository;
use Psr\Clock\ClockInterface;

/**
 * Administration des comptes pro depuis l'admin (#47) : suspension/réactivation et
 * prolongation d'essai. Chaque décision est tracée au journal d'audit (#50). La
 * logique vit ici, hors contrôleur ; les transitions sont idempotentes.
 */
final readonly class AdministrationComptes
{
    public function __construct(
        private CompteRepository $comptes,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    public function suspendre(int $tenantId, string $auteur): bool
    {
        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte || $compte->estSuspendu()) {
            return false;
        }

        $compte->suspendre($this->clock->now());
        $this->comptes->enregistrer($compte);
        $this->tracer($auteur, 'compte.suspendu', $tenantId);

        return true;
    }

    public function reactiver(int $tenantId, string $auteur): bool
    {
        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte || !$compte->estSuspendu()) {
            return false;
        }

        $compte->reactiver();
        $this->comptes->enregistrer($compte);
        $this->tracer($auteur, 'compte.reactive', $tenantId);

        return true;
    }

    public function prolongerEssai(int $tenantId, int $jours, string $auteur): bool
    {
        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte) {
            return false;
        }

        $jusqua = $this->clock->now()->add(new \DateInterval('P'.max(1, $jours).'D'));
        $compte->prolongerEssaiJusqua($jusqua);
        $this->comptes->enregistrer($compte);
        $this->tracer($auteur, 'compte.essai_prolonge', $tenantId, ['jours' => $jours, 'jusqua' => $jusqua->format(\DATE_ATOM)]);

        return true;
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function tracer(string $auteur, string $action, int $tenantId, array $contexte = []): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: $action,
            cible: 'compte#'.$tenantId,
            contexte: ['tenant_id' => $tenantId, ...$contexte],
            survenuLe: $this->clock->now(),
        ));
    }
}
