<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditDoctrine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le journal Doctrine est exercé contre un vrai PostGIS
 * (base « valeur_verte_test »), chaque test isolé par une transaction annulée (DAMA).
 */
final class JournalAuditDoctrineTest extends KernelTestCase
{
    private JournalAuditDoctrine $journal;

    protected function setUp(): void
    {
        self::bootKernel();
        // Résolu via l'EntityManager : le repository n'a pas encore de consommateur,
        // le conteneur de test l'élaguerait s'il était demandé en direct.
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $journal = $manager->getRepository(EvenementAudit::class);
        self::assertInstanceOf(JournalAuditDoctrine::class, $journal);
        $this->journal = $journal;
    }

    public function testEnregistreEtReliteLesEvenementsLesPlusRecentsDAbord(): void
    {
        $this->journal->enregistrer($this->evenement('compte.creation', '2026-08-13 09:00:00'));
        $this->journal->enregistrer($this->evenement('coefficient.flag_aberrant', '2026-08-13 11:00:00'));

        $this->viderIdentityMap();
        $recents = $this->journal->evenementsRecents();

        self::assertCount(2, $recents);
        self::assertSame('coefficient.flag_aberrant', $recents[0]->action());
        self::assertSame('compte.creation', $recents[1]->action());
    }

    public function testLeContexteEstPersisteEtReconstruitDepuisLaBase(): void
    {
        $contexte = ['code_insee' => '31555', 'erreur_mediane' => 42.0];
        $this->journal->enregistrer($this->evenement('coefficient.flag_aberrant', '2026-08-13 10:00:00', $contexte));

        $this->viderIdentityMap();
        $relu = $this->journal->evenementsRecents()[0];

        self::assertSame('n.moral@live.fr', $relu->acteur());
        self::assertSame('maille:31555', $relu->cible());
        self::assertEquals($contexte, $relu->contexte());
    }

    public function testLaLimiteBorneLeNombreDEvenementsRelus(): void
    {
        $this->journal->enregistrer($this->evenement('a', '2026-08-13 08:00:00'));
        $this->journal->enregistrer($this->evenement('b', '2026-08-13 09:00:00'));
        $this->journal->enregistrer($this->evenement('c', '2026-08-13 10:00:00'));

        $this->viderIdentityMap();
        $recents = $this->journal->evenementsRecents(2);

        self::assertCount(2, $recents);
        self::assertSame('c', $recents[0]->action());
        self::assertSame('b', $recents[1]->action());
    }

    public function testEvenementsParPrefixeNeReliteQueLesActionsDuPrefixe(): void
    {
        $this->journal->enregistrer($this->evenement('rgpd.demande_recue', '2026-08-13 09:00:00'));
        $this->journal->enregistrer($this->evenement('compte.suspendu', '2026-08-13 10:00:00'));
        $this->journal->enregistrer($this->evenement('rgpd.export_genere', '2026-08-13 11:00:00'));

        $this->viderIdentityMap();
        $rgpd = $this->journal->evenementsParPrefixe('rgpd.');

        self::assertCount(2, $rgpd);
        self::assertSame('rgpd.export_genere', $rgpd[0]->action());
        self::assertSame('rgpd.demande_recue', $rgpd[1]->action());
    }

    /**
     * @param array<string, mixed> $contexte
     */
    private function evenement(string $action, string $survenuLe, array $contexte = []): EvenementAudit
    {
        return new EvenementAudit(
            acteur: 'n.moral@live.fr',
            action: $action,
            cible: 'maille:31555',
            contexte: $contexte,
            survenuLe: new \DateTimeImmutable($survenuLe),
        );
    }

    private function viderIdentityMap(): void
    {
        // La relecture doit venir réellement de la base, pas de l'identity map.
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
