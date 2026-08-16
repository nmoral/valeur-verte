<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

use App\Tenant\TraceDesactivationTenant;
use Psr\Clock\ClockInterface;

/**
 * Traçabilité de la levée du cloisonnement multi-tenant (#54) : l'acte est
 * inscrit au journal d'audit persistant (#50), en remplacement du log d'attente.
 */
final class TraceDesactivationViaJournal implements TraceDesactivationTenant
{
    public function __construct(
        private readonly JournalAudit $journal,
        private readonly ClockInterface $clock,
    ) {
    }

    public function tracer(string $raison, string $auteur): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'tenant.cloisonnement_leve',
            cible: 'tenant:*',
            contexte: ['raison' => $raison],
            survenuLe: $this->clock->now(),
        ));
    }
}
