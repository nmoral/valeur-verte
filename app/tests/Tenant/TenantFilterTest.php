<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantFilterTest extends KernelTestCase
{
    public function testLeRapportEstUneEntiteTenantAware(): void
    {
        self::bootKernel();

        self::assertContains(TenantAwareInterface::class, class_implements(Rapport::class));
    }

    public function testLeFiltreNeRenvoieQueLesRapportsDuTenantCourant(): void
    {
        self::bootKernel();
        $em = $this->entityManager();
        $rapports = $this->rapportRepository();

        $rapports->enregistrer($this->rapportPour('2026-T1', 'Baziège', 1));
        $rapports->enregistrer($this->rapportPour('2026-T2', 'Montgiscard', 2));

        $em->getFilters()->enable('tenant')->setParameter('tenant_id', 1);
        $vusParLeTenant1 = $rapports->recents();

        self::assertCount(1, $vusParLeTenant1);
        self::assertSame('2026-T1', $vusParLeTenant1[0]->reference());

        $em->getFilters()->getFilter('tenant')->setParameter('tenant_id', 2);
        $vusParLeTenant2 = $rapports->recents();

        self::assertCount(1, $vusParLeTenant2);
        self::assertSame('2026-T2', $vusParLeTenant2[0]->reference());
    }

    public function testLaPersistanceAttribueLeTenantCourant(): void
    {
        self::bootKernel();
        $this->tenantContext()->definir(7);

        $rapport = $this->rapportPour('2026-T7', 'Ramonville', null);
        $this->rapportRepository()->enregistrer($rapport);

        self::assertSame(7, $rapport->getTenantId());
    }

    private function rapportPour(string $reference, string $ville, ?int $tenantId): Rapport
    {
        $rapport = Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            $ville,
            Dpe::E,
            Dpe::C,
            52610,
            '/rapports/'.$reference.'.pdf',
        );
        if (null !== $tenantId) {
            $rapport->setTenantId($tenantId);
        }

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

    private function tenantContext(): TenantContext
    {
        $context = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $context);

        return $context;
    }
}
