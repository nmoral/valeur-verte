<?php

declare(strict_types=1);

namespace App\Tests\Liste\Source;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use App\Liste\ColonnesTriables;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceQueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Intégration : l'adaptateur ORM pousse recherche (LIKE bindé), tri (liste
 * blanche) et LIMIT/OFFSET en base, avec un COUNT correct. On s'appuie sur
 * Signalement (schéma app, sans cloisonnement tenant).
 */
final class SourceQueryBuilderTest extends KernelTestCase
{
    private SignalementRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(SignalementRepository::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);
        $this->repository = $repository;

        $this->repository->enregistrer(Signalement::creer('Toulouse rue A', [], null, new \DateTimeImmutable('2026-01-01')));
        $this->repository->enregistrer(Signalement::creer('Auch place B', [], null, new \DateTimeImmutable('2026-01-02')));
        $this->repository->enregistrer(Signalement::creer('Foix chemin C', [], 'douteux', new \DateTimeImmutable('2026-01-03')));
    }

    /**
     * @return SourceQueryBuilder<Signalement>
     */
    private function source(): SourceQueryBuilder
    {
        /** @var SourceQueryBuilder<Signalement> $source */
        $source = new SourceQueryBuilder(
            fn (): \Doctrine\ORM\QueryBuilder => $this->repository->createQueryBuilder('s'),
            ColonnesTriables::de('date', 'adresse'),
            ['date' => 's.signaleLe', 'adresse' => 's.adresse'],
            ['s.adresse', 's.message'],
            's.id',
        );

        return $source;
    }

    private function triables(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'adresse');
    }

    public function testTriParAdresseAscendant(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(1, 10, 'adresse', 'asc', '', $this->triables()));

        self::assertSame(
            ['Auch place B', 'Foix chemin C', 'Toulouse rue A'],
            array_map(static fn (Signalement $s): string => $s->adresse(), $page->elements()),
        );
        self::assertSame(3, $page->total());
    }

    public function testRechercheBindeeFiltreSurLAdresse(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(1, 10, 'date', 'desc', 'foix', $this->triables()));

        self::assertSame(1, $page->total());
        self::assertSame('Foix chemin C', $page->elements()[0]->adresse());
    }

    public function testRechercheCouvreLeMessage(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(1, 10, 'date', 'desc', 'douteux', $this->triables()));

        self::assertSame(1, $page->total());
        self::assertSame('Foix chemin C', $page->elements()[0]->adresse());
    }

    public function testPaginationPousseLimitOffsetEtCompteTout(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(2, 2, 'adresse', 'asc', '', $this->triables()));

        // Page 2 sur un total de 3, taille 2 → un seul élément (le 3e trié).
        self::assertCount(1, $page->elements());
        self::assertSame('Toulouse rue A', $page->elements()[0]->adresse());
        self::assertSame(3, $page->total());
        self::assertSame(2, $page->pages());
    }
}
