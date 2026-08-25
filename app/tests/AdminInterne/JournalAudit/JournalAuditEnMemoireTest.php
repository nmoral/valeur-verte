<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use PHPUnit\Framework\TestCase;

final class JournalAuditEnMemoireTest extends TestCase
{
    public function testUnEvenementEnregistreEstRestitue(): void
    {
        $journal = new JournalAuditEnMemoire();
        $evenement = $this->evenement('coefficient.flag_aberrant', '2026-08-13 10:00:00');

        $journal->enregistrer($evenement);

        self::assertSame([$evenement], $journal->evenementsRecents());
    }

    public function testLesEvenementsSontRestituesDuPlusRecentAuPlusAncien(): void
    {
        $journal = new JournalAuditEnMemoire();
        $ancien = $this->evenement('compte.creation', '2026-08-13 09:00:00');
        $recent = $this->evenement('coefficient.flag_aberrant', '2026-08-13 11:00:00');

        // Enregistrés dans le désordre chronologique : l'ordre de sortie suit le temps, pas l'insertion.
        $journal->enregistrer($recent);
        $journal->enregistrer($ancien);

        self::assertSame([$recent, $ancien], $journal->evenementsRecents());
    }

    public function testLaLimiteBorneLeNombreDEvenementsRetournes(): void
    {
        $journal = new JournalAuditEnMemoire();
        $premier = $this->evenement('a', '2026-08-13 08:00:00');
        $deuxieme = $this->evenement('b', '2026-08-13 09:00:00');
        $troisieme = $this->evenement('c', '2026-08-13 10:00:00');
        $journal->enregistrer($premier);
        $journal->enregistrer($deuxieme);
        $journal->enregistrer($troisieme);

        self::assertSame([$troisieme, $deuxieme], $journal->evenementsRecents(2));
    }

    public function testEvenementsParPrefixeNeGardeQueLesActionsCommencantParLePrefixe(): void
    {
        $journal = new JournalAuditEnMemoire();
        $recue = $this->evenement('rgpd.demande_recue', '2026-08-13 09:00:00');
        $exporte = $this->evenement('rgpd.export_genere', '2026-08-13 11:00:00');
        $horsSujet = $this->evenement('compte.suspendu', '2026-08-13 12:00:00');
        $journal->enregistrer($recue);
        $journal->enregistrer($horsSujet);
        $journal->enregistrer($exporte);

        // Filtré sur « rgpd. » et trié du plus récent au plus ancien.
        self::assertSame([$exporte, $recue], $journal->evenementsParPrefixe('rgpd.'));
    }

    public function testEvenementsParPrefixeBorneLeNombreRetourne(): void
    {
        $journal = new JournalAuditEnMemoire();
        $journal->enregistrer($this->evenement('rgpd.demande_recue', '2026-08-13 08:00:00'));
        $recent = $this->evenement('rgpd.export_genere', '2026-08-13 10:00:00');
        $journal->enregistrer($recent);

        self::assertSame([$recent], $journal->evenementsParPrefixe('rgpd.', 1));
    }

    private function evenement(string $action, string $survenuLe): EvenementAudit
    {
        return new EvenementAudit(
            acteur: 'n.moral@live.fr',
            action: $action,
            cible: 'maille:31555',
            contexte: [],
            survenuLe: new \DateTimeImmutable($survenuLe),
        );
    }
}
