<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

/**
 * Port du journal d'audit (#50) : registre append-only des événements sensibles
 * de l'admin interne. On y inscrit ; on n'en retire ni n'en modifie jamais.
 */
interface JournalAudit
{
    public function enregistrer(EvenementAudit $evenement): void;

    /**
     * Les événements les plus récents d'abord, bornés par $limite.
     *
     * @return list<EvenementAudit>
     */
    public function evenementsRecents(int $limite = 50): array;

    /**
     * Les événements dont l'action commence par $prefixe, les plus récents
     * d'abord, bornés par $limite. Sert le suivi des demandes RGPD (#309), qui
     * s'adosse au journal (`rgpd.*`) plutôt qu'à un registre dédié.
     *
     * @return list<EvenementAudit>
     */
    public function evenementsParPrefixe(string $prefixe, int $limite = 50): array;
}
