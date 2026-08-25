<?php

declare(strict_types=1);

namespace App\AdminInterne\JournalAudit;

/**
 * Implémentation en mémoire du journal d'audit — support des tests et socle
 * du comportement avant le branchement Doctrine (#50).
 */
final class JournalAuditEnMemoire implements JournalAudit
{
    /**
     * @var list<EvenementAudit>
     */
    private array $evenements = [];

    public function enregistrer(EvenementAudit $evenement): void
    {
        $this->evenements[] = $evenement;
    }

    public function evenementsRecents(int $limite = 50): array
    {
        $evenements = $this->evenements;
        usort(
            $evenements,
            static fn (EvenementAudit $a, EvenementAudit $b): int => $b->survenuLe() <=> $a->survenuLe(),
        );

        return \array_slice($evenements, 0, $limite);
    }

    public function evenementsParPrefixe(string $prefixe, int $limite = 50): array
    {
        $evenements = array_values(array_filter(
            $this->evenements,
            static fn (EvenementAudit $e): bool => str_starts_with($e->action(), $prefixe),
        ));
        usort(
            $evenements,
            static fn (EvenementAudit $a, EvenementAudit $b): int => $b->survenuLe() <=> $a->survenuLe(),
        );

        return \array_slice($evenements, 0, $limite);
    }
}
