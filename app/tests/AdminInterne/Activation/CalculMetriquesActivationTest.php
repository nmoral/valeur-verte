<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Activation;

use App\AdminInterne\Activation\CalculMetriquesActivation;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Test d'intégration : les métriques d'activation du parcours self-service (#111)
 * se lisent des horodatages déjà en base (compte.cree_le, rapport.cree_le) — aucune
 * instrumentation. Le « premier rapport » est un rapport produit par le pro en mode
 * audit ; un rapport widget (lead) ne vaut pas activation. Lecture seule, DBAL, hors
 * filtre multi-tenant, comme le compteur de rapports (#47).
 *
 * Corpus commun (maintenant figé au 2026-08-25 09:00) :
 *  - t1 créé 06-01 09:00, 3 rapports audit dont le 1er à +20 min, tous dans les 30 j ;
 *  - t2 créé 06-01 09:00, 1 rapport audit à +90 min, + 1 rapport widget à +5 min (ne compte pas) ;
 *  - t3 créé 08-20 09:00 (récent, non mûr pour J+30), 1 rapport audit à +40 min ;
 *  - t4 créé 06-01 09:00, un seul rapport widget (jamais activé).
 */
final class CalculMetriquesActivationTest extends KernelTestCase
{
    public function testTauxInscriptionVersPremierRapportCompteParCompte(): void
    {
        self::bootKernel();
        $this->seedCorpus();

        $premier = $this->calcul()->calculer()->premierRapport();

        // t1, t2, t3 ont un rapport audit ; t4 n'a qu'un lead widget → non activé.
        self::assertSame(3, $premier->numerateur());
        self::assertSame(4, $premier->denominateur());
        self::assertSame(75.0, $premier->pourcentage());
    }

    public function testDelaiMedianInscriptionVersPremierRapportEnMinutes(): void
    {
        self::bootKernel();
        $this->seedCorpus();

        // Délais des 1ers rapports audit : t1=20, t2=90, t3=40 → médiane 40 min.
        // Le rapport widget de t2 (+5 min) ne doit pas rabaisser sa médiane.
        self::assertSame(40, $this->calcul()->calculer()->delaiMedianMinutes());
    }

    public function testTroisRapportsAJTrenteSurLesSeulsComptesMurs(): void
    {
        self::bootKernel();
        $this->seedCorpus();

        $j30 = $this->calcul()->calculer()->troisRapportsAJTrente();

        // Comptes mûrs (créés il y a ≥ 30 j) : t1, t2, t4. t3 est trop récent.
        // Seul t1 a produit ≥ 3 rapports dans ses 30 premiers jours.
        self::assertSame(1, $j30->numerateur());
        self::assertSame(3, $j30->denominateur());
        self::assertSame(33.3, $j30->pourcentage());
    }

    public function testSansAucunCompteLesTauxSontIndefinisPasUneDivisionParZero(): void
    {
        self::bootKernel();

        $metriques = $this->calcul()->calculer();

        self::assertSame(0, $metriques->premierRapport()->denominateur());
        self::assertNull($metriques->premierRapport()->pourcentage());
        self::assertNull($metriques->delaiMedianMinutes());
        self::assertNull($metriques->troisRapportsAJTrente()->pourcentage());
    }

    private function seedCorpus(): void
    {
        $this->seedCompte(1, '2026-06-01 09:00:00');
        $this->seedCompte(2, '2026-06-01 09:00:00');
        $this->seedCompte(3, '2026-08-20 09:00:00');
        $this->seedCompte(4, '2026-06-01 09:00:00');

        // t1 : 3 rapports audit dans les 30 jours, 1er à +20 min.
        $this->seedRapport('t1-1', 1, '2026-06-01 09:20:00', Source::Audit);
        $this->seedRapport('t1-2', 1, '2026-06-10 09:00:00', Source::Audit);
        $this->seedRapport('t1-3', 1, '2026-06-20 09:00:00', Source::Audit);

        // t2 : 1 rapport audit à +90 min, + 1 lead widget à +5 min (ne compte pas).
        $this->seedRapport('t2-1', 2, '2026-06-01 10:30:00', Source::Audit);
        $this->seedRapport('t2-w', 2, '2026-06-01 09:05:00', Source::Widget);

        // t3 : récent, 1 rapport audit à +40 min.
        $this->seedRapport('t3-1', 3, '2026-08-20 09:40:00', Source::Audit);

        // t4 : uniquement un lead widget → jamais activé.
        $this->seedRapport('t4-w', 4, '2026-06-15 09:00:00', Source::Widget);
    }

    private function seedCompte(int $tenantId, string $creeLe): void
    {
        $this->comptes()->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable($creeLe)));
    }

    private function seedRapport(string $reference, int $tenantId, string $creeLe, Source $source): void
    {
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable($creeLe),
            $source,
            InstantaneRapportMother::creer(adresse: 'Baziège'),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);
        $this->rapports()->enregistrer($rapport);
    }

    private function calcul(): CalculMetriquesActivation
    {
        return new CalculMetriquesActivation(
            $this->connexion(),
            new MockClock(new \DateTimeImmutable('2026-08-25 09:00:00')),
        );
    }

    private function comptes(): CompteRepository
    {
        $repository = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $repository);

        return $repository;
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }

    private function connexion(): \Doctrine\DBAL\Connection
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getConnection();
    }
}
