<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\ReglageParametresFormule;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\Compte\Formule;
use App\Compte\ReglagesFormuleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Régler les paramètres d'une formule met à jour la source unique ET trace le
 * changement au journal d'audit (#50/#61). Un plafond nul (0) vaut « illimité ».
 */
final class ReglageParametresFormuleTest extends KernelTestCase
{
    public function testReglerMetAJourLesParametresEtTrace(): void
    {
        self::bootKernel();
        $reglages = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $reglages);
        $journal = new JournalAuditEnMemoire();

        (new ReglageParametresFormule($reglages, $journal, new MockClock(new \DateTimeImmutable('2026-08-14 10:00:00'))))
            ->regler(Formule::Fondateur, 200, 120, 0, 'admin@example.com');

        $parametres = $reglages->pour(Formule::Fondateur);
        self::assertSame(200, $parametres->dureeEssaiJours());
        self::assertSame(120, $parametres->quotaRapports());
        self::assertNull($parametres->plafondComptes(), 'Plafond 0 → illimité.');

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        self::assertSame('formule.modifiee', $evenements[0]->action());
        self::assertSame('formule.fondateur', $evenements[0]->cible());
    }
}
