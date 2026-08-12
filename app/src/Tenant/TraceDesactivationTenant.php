<?php

declare(strict_types=1);

namespace App\Tenant;

/**
 * Port de traçabilité : la levée du cloisonnement multi-tenant est un acte
 * volontaire qui doit être journalisé (auteur, raison, horodatage). Le socle
 * d'audit réel (EasyAdmin, persistance) est porté par #50.
 */
interface TraceDesactivationTenant
{
    public function tracer(string $raison, string $auteur): void;
}
