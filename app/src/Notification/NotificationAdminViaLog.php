<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;

/**
 * Implémentation d'attente : journalise l'alerte. Sera remplacée par le canal
 * d'admin interne (#50).
 */
final class NotificationAdminViaLog implements NotificationAdmin
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function usageAnormal(string $sujet, string $contexte): void
    {
        $this->logger->warning('Usage anormal signalé à l\'admin.', [
            'sujet' => $sujet,
            'contexte' => $contexte,
        ]);
    }
}
