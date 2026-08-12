<?php

declare(strict_types=1);

namespace App\Tenant;

use Psr\Log\LoggerInterface;

/**
 * Implémentation d'attente : journalise la levée du cloisonnement dans les logs.
 * Sera remplacée par le journal d'audit persistant (#50, socle EasyAdmin).
 */
final class TraceDesactivationViaLog implements TraceDesactivationTenant
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function tracer(string $raison, string $auteur): void
    {
        $this->logger->warning('Cloisonnement multi-tenant levé.', [
            'auteur' => $auteur,
            'raison' => $raison,
        ]);
    }
}
