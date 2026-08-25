<?php

declare(strict_types=1);

namespace App\Compte\Export;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use Psr\Clock\ClockInterface;

/**
 * Produit l'export d'un compte et **trace** l'acte au journal d'audit (#309).
 * Point de passage unique pour les deux zones — le pro qui exerce sa portabilité
 * depuis « Mon compte », l'admin qui honore une demande reçue par email : dans
 * les deux cas l'acte laisse une trace (`rgpd.export_genere`, acteur + cible),
 * ce qui vaut aussi non-silence de l'accès inter-tenant côté admin.
 */
final readonly class GenererExportCompte
{
    public function __construct(
        private ExportDonneesCompte $export,
        private JournalAudit $journal,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function pour(int $tenantId, string $auteur): array
    {
        $document = $this->export->exporter($tenantId);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $auteur,
            action: 'rgpd.export_genere',
            cible: 'compte#'.$tenantId,
            contexte: ['tenant_id' => $tenantId, 'format' => 'json'],
            survenuLe: $this->clock->now(),
        ));

        return $document;
    }
}
