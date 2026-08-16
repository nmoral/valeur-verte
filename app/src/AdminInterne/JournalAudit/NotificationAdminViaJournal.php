<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

use App\Notification\NotificationAdmin;
use Psr\Clock\ClockInterface;

/**
 * Canal d'admin interne (#50) : un usage anormal (#44) est inscrit au journal
 * d'audit persistant, en remplacement de l'implémentation d'attente par log.
 */
final class NotificationAdminViaJournal implements NotificationAdmin
{
    public function __construct(
        private readonly JournalAudit $journal,
        private readonly ClockInterface $clock,
    ) {
    }

    public function usageAnormal(string $sujet, string $contexte): void
    {
        $this->journal->enregistrer(new EvenementAudit(
            acteur: 'systeme',
            action: 'usage.anormal',
            cible: $sujet,
            contexte: ['detail' => $contexte],
            survenuLe: $this->clock->now(),
        ));
    }
}
