<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tenant\AccesTousTenants;
use App\Tenant\TraceDesactivationTenant;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccesTousTenantsTest extends KernelTestCase
{
    public function testLAdminVoitTousLesTenantsLeTempsDeLActionEtLeFiltreEstReactive(): void
    {
        self::bootKernel();
        $em = $this->entityManager();
        $rapports = $this->rapportRepository();
        $journal = new TraceDesactivationEspion();

        $rapports->enregistrer($this->rapportPour('2026-T1', 1));
        $rapports->enregistrer($this->rapportPour('2026-T2', 2));
        $em->getFilters()->enable('tenant')->setParameter('tenant_id', 1);

        $acces = new AccesTousTenants($em, $journal);
        $tous = $acces->executer('revue support', 'admin@interne', static fn (): array => $rapports->recents());

        // Pendant l'action, le cloisonnement est levé : tous les tenants sont visibles.
        self::assertCount(2, $tous);
        // Après l'action, le filtre est de nouveau actif sur le tenant courant.
        self::assertTrue($em->getFilters()->isEnabled('tenant'));
        self::assertCount(1, $rapports->recents());
    }

    public function testLaDesactivationEstTraceeAvecAuteurEtRaison(): void
    {
        self::bootKernel();
        $journal = new TraceDesactivationEspion();

        $acces = new AccesTousTenants($this->entityManager(), $journal);
        $acces->executer('export mensuel', 'admin@interne', static fn (): int => 42);

        self::assertCount(1, $journal->traces);
        self::assertSame('export mensuel', $journal->traces[0]['raison']);
        self::assertSame('admin@interne', $journal->traces[0]['auteur']);
    }

    private function rapportPour(string $reference, int $tenantId): Rapport
    {
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(),
            '/rapports/'.$reference.'.pdf',
        );
        $rapport->setTenantId($tenantId);

        return $rapport;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function rapportRepository(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}

/**
 * Faux journal : capture les désactivations sans dépendre du socle d'audit (#50).
 */
final class TraceDesactivationEspion implements TraceDesactivationTenant
{
    /**
     * @var list<array{raison: string, auteur: string}>
     */
    public array $traces = [];

    public function tracer(string $raison, string $auteur): void
    {
        $this->traces[] = ['raison' => $raison, 'auteur' => $auteur];
    }
}
