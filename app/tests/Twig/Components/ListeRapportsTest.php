<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Comportement du LiveComponent de liste des rapports : recherche via le harnais
 * LiveComponent. (La suppression de groupe cloisonnée tenant est couverte par
 * RapportRepositoryTest ; le câblage executerGroupe l'est par ListeSignalementsTest.).
 */
final class ListeRapportsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        $premier = $this->rapport('2026-0001', '2026-08-01 09:00:00');
        $premier->setTenantId(1);
        $second = $this->rapport('2026-0002', '2026-08-05 09:00:00');
        $second->setTenantId(1);
        $repository->enregistrer($premier);
        $repository->enregistrer($second);
    }

    public function testRechercheParReference(): void
    {
        $composant = $this->createLiveComponent('ListeRapports');

        $html = (string) $composant->set('recherche', '2026-0002')->render();

        self::assertStringContainsString('2026-0002', $html);
        self::assertStringNotContainsString('2026-0001', $html);
    }

    private function rapport(string $reference, string $creeLe): Rapport
    {
        return Rapport::creer(
            $reference,
            new \DateTimeImmutable($creeLe),
            Source::Audit,
            InstantaneRapportMother::creer(),
            '/rapports/'.$reference.'.pdf',
        );
    }
}
