<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\RetentionRapports;
use App\BackOffice\Rapport\Source;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rétention des rapports (décision D10, #68).
 *
 * Deux échéances, comptées depuis la production du rapport — faute de suivi
 * d'interaction, c'est le dernier contact connu :
 *
 * - **24 mois** : anonymisation. Le contact disparaît, l'adresse tombe à la
 *   commune ; le rapport reste consultable et ses chiffres sont intacts.
 * - **3 ans** : suppression. La ligne disparaît.
 *
 * Le balayage est **global** : joué en CLI, sans requête, le filtre de
 * cloisonnement n'est pas paramétré et ne contraint donc rien (`TenantFilter`).
 * C'est voulu — une rétention qui s'arrêterait au tenant courant ne purgerait
 * jamais rien.
 */
final class RetentionRapportsTest extends KernelTestCase
{
    private const string MAINTENANT = '2026-08-22 03:10:00';
    private const int ANONYMISATION_MOIS = 24;
    private const int SUPPRESSION_MOIS = 36;
    private const string ADRESSE = '12 Rue des Lilas 31000 Toulouse';
    private const string COMMANDE = 'app:rapport:appliquer-retention';

    private RapportRepository $rapports;

    protected function setUp(): void
    {
        self::bootKernel();
        $rapports = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $rapports);
        $this->rapports = $rapports;
    }

    public function testUnRapportDePlusDeVingtQuatreMoisEstAnonymise(): void
    {
        $rapport = $this->rapportProduitLe('2024-01-15 09:00:00', Contact::creer('Mme Cazeneuve', 'caze@example.com', '0561000000'));

        $bilan = $this->retention()->executer();

        self::assertSame(1, $bilan->anonymises());
        self::assertSame(0, $bilan->supprimes());
        $relu = $this->relire($rapport);
        self::assertNull($relu->contact());
        self::assertSame('31000 Toulouse', $relu->adresse());
        self::assertTrue($relu->estAnonymise());
    }

    public function testUnRapportDeMoinsDeVingtQuatreMoisNEstPasTouche(): void
    {
        $rapport = $this->rapportProduitLe('2026-01-15 09:00:00', Contact::creer('Mme Cazeneuve', 'caze@example.com', null));

        $bilan = $this->retention()->executer();

        self::assertSame(0, $bilan->anonymises());
        $relu = $this->relire($rapport);
        self::assertNotNull($relu->contact());
        self::assertSame(self::ADRESSE, $relu->adresse());
    }

    public function testLaBorneDAnonymisationEstExacte(): void
    {
        $limite = new \DateTimeImmutable(self::MAINTENANT.' -'.self::ANONYMISATION_MOIS.' months');
        $garde = $this->rapportProduitLe($limite->modify('+1 second')->format('Y-m-d H:i:s'));
        $anonymise = $this->rapportProduitLe($limite->modify('-1 second')->format('Y-m-d H:i:s'));

        self::assertSame(1, $this->retention()->executer()->anonymises());

        self::assertFalse($this->relire($garde)->estAnonymise());
        self::assertTrue($this->relire($anonymise)->estAnonymise());
    }

    public function testUnRapportDePlusDeTroisAnsEstSupprime(): void
    {
        // Il n'est pas anonymisé au passage : on supprime d'abord, on anonymise
        // ensuite, pour que les deux comptes ne se recouvrent jamais.
        $rapport = $this->rapportProduitLe('2022-06-01 09:00:00');

        $bilan = $this->retention()->executer();

        self::assertSame(1, $bilan->supprimes());
        self::assertSame(0, $bilan->anonymises());
        self::assertNull($this->rapports->parReference($rapport->reference()));
    }

    public function testLaRetentionEstIdempotente(): void
    {
        $this->rapportProduitLe('2024-01-15 09:00:00', Contact::creer('Mme Cazeneuve', 'caze@example.com', null));

        self::assertSame(1, $this->retention()->executer()->anonymises());
        $second = $this->retention()->executer();

        self::assertSame(0, $second->anonymises(), 'Un rapport déjà anonymisé ne se recompte pas.');
        self::assertSame(0, $second->supprimes());
    }

    public function testLeBalayageTraverseLesTenants(): void
    {
        // Une rétention qui s'arrêterait au tenant courant ne purgerait jamais rien.
        $premier = $this->rapportProduitLe('2024-01-15 09:00:00', tenantId: 1);
        $second = $this->rapportProduitLe('2024-02-15 09:00:00', tenantId: 2);

        self::assertSame(2, $this->retention()->executer()->anonymises());

        self::assertTrue($this->relire($premier)->estAnonymise());
        self::assertTrue($this->relire($second)->estAnonymise());
    }

    public function testLaCommandeAppliqueLaRetentionEtRendCompte(): void
    {
        // Durées réelles de configuration : un rapport de 2020 est largement
        // au-delà des deux échéances.
        $rapport = $this->rapportProduitLe('2020-03-01 09:00:00');
        $tester = new CommandTester($this->commande());

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('supprimé', $tester->getDisplay());
        self::assertNull($this->rapports->parReference($rapport->reference()));
    }

    public function testLaCommandeEstIdempotente(): void
    {
        // Rejouée dans la foulée, elle ne trouve plus rien : un cron quotidien
        // ne doit jamais rougir à vide.
        $tester = new CommandTester($this->commande());

        $tester->execute([]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 rapport(s) anonymisé(s)', $tester->getDisplay());
        self::assertStringContainsString('0 rapport(s) supprimé(s)', $tester->getDisplay());
    }

    private function commande(): \Symfony\Component\Console\Command\Command
    {
        return (new Application(self::$kernel ?? self::bootKernel()))->find(self::COMMANDE);
    }

    private function retention(): RetentionRapports
    {
        return new RetentionRapports(
            $this->rapports,
            new MockClock(new \DateTimeImmutable(self::MAINTENANT)),
            self::ANONYMISATION_MOIS,
            self::SUPPRESSION_MOIS,
        );
    }

    private function rapportProduitLe(string $instant, ?Contact $contact = null, int $tenantId = 1): Rapport
    {
        $reference = bin2hex(random_bytes(8));
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable($instant),
            Source::Audit,
            InstantaneRapportMother::creer(self::ADRESSE),
            '/rapports/'.$reference.'.pdf',
            $contact,
        );
        $rapport->setTenantId($tenantId);
        $this->rapports->enregistrer($rapport);

        return $rapport;
    }

    private function relire(Rapport $rapport): Rapport
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $relu = $this->rapports->parReference($rapport->reference());
        self::assertInstanceOf(Rapport::class, $relu);

        return $relu;
    }
}
