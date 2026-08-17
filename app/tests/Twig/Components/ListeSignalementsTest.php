<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Comportement du LiveComponent de liste des signalements : tri (avec bascule de
 * sens), recherche et rendu paginé, exercés via le harnais LiveComponent.
 */
final class ListeSignalementsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(SignalementRepository::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);

        $repository->enregistrer(Signalement::creer('Toulouse rue A', ['dpe_avant' => 'E', 'dpe_apres' => 'C'], null, new \DateTimeImmutable('2026-01-01')));
        $repository->enregistrer(Signalement::creer('Auch place B', ['dpe_avant' => 'F', 'dpe_apres' => 'D'], null, new \DateTimeImmutable('2026-01-02')));
        $repository->enregistrer(Signalement::creer('Foix chemin C', ['dpe_avant' => 'G', 'dpe_apres' => 'E'], 'douteux', new \DateTimeImmutable('2026-01-03')));
    }

    public function testRenduInitialLesPlusRecentsDAbord(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');

        $html = (string) $composant->render();

        // Tri par défaut date desc : Foix (03/01) avant Auch (02/01) avant Toulouse (01/01).
        self::assertLessThan(strpos($html, 'Auch place B'), strpos($html, 'Foix chemin C'));
        self::assertLessThan(strpos($html, 'Toulouse rue A'), strpos($html, 'Auch place B'));
        self::assertStringContainsString('Page 1 / 1 — 3', $html);
    }

    public function testTrierParAdresseAscendantPuisBascule(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');

        $html = (string) $composant->call('trier', ['colonne' => 'adresse'])->render();
        self::assertLessThan(strpos($html, 'Foix chemin C'), strpos($html, 'Auch place B'));

        // Re-cliquer sur la même colonne inverse le sens.
        $html = (string) $composant->call('trier', ['colonne' => 'adresse'])->render();
        self::assertLessThan(strpos($html, 'Auch place B'), strpos($html, 'Toulouse rue A'));
    }

    public function testRechercheFiltreLaListe(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');

        $html = (string) $composant->set('recherche', 'foix')->render();

        self::assertStringContainsString('Foix chemin C', $html);
        self::assertStringNotContainsString('Toulouse rue A', $html);
        self::assertStringContainsString('Page 1 / 1 — 1', $html);
    }

    public function testRechercheRemetEnPremierePage(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');

        $composant->call('allerPage', ['page' => 2]);
        $html = (string) $composant->set('recherche', 'chemin')->render();

        self::assertStringContainsString('Page 1 / 1 — 1', $html);
    }
}
