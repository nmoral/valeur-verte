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

    private Signalement $toulouse;
    private Signalement $foix;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(SignalementRepository::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);

        $this->toulouse = Signalement::creer('Toulouse rue A', ['dpe_avant' => 'E', 'dpe_apres' => 'C'], null, new \DateTimeImmutable('2026-01-01'));
        $auch = Signalement::creer('Auch place B', ['dpe_avant' => 'F', 'dpe_apres' => 'D'], null, new \DateTimeImmutable('2026-01-02'));
        $this->foix = Signalement::creer('Foix chemin C', ['dpe_avant' => 'G', 'dpe_apres' => 'E'], 'douteux', new \DateTimeImmutable('2026-01-03'));
        $repository->enregistrer($this->toulouse);
        $repository->enregistrer($auch);
        $repository->enregistrer($this->foix);
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

    public function testChoisirUneTailleDePageLimiteLesLignesEtRevientEnPremierePage(): void
    {
        // #239/#110 : le pro choisit combien de lignes afficher. En changer remet en
        // première page (la page courante n'a plus de sens à la nouvelle taille).
        $repository = self::getContainer()->get(SignalementRepository::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);
        for ($i = 0; $i < 9; ++$i) {
            $repository->enregistrer(Signalement::creer("Ville {$i}", ['dpe_avant' => 'E', 'dpe_apres' => 'C'], null, new \DateTimeImmutable('2026-02-0'.($i + 1))));
        }

        $composant = $this->createLiveComponent('ListeSignalements');
        // 12 signalements, 25 par page par défaut : une seule page.
        self::assertStringContainsString('Page 1 / 1 — 12', (string) $composant->render());

        // 10 par page → 2 pages, remis en page 1.
        self::assertStringContainsString('Page 1 / 2 — 12', (string) $composant->set('parPageChoisi', 10)->render());

        // Aller en page 2 puis rechoisir 25 : on revient en page 1 (une seule page).
        $composant->call('allerPage', ['page' => 2]);
        self::assertStringContainsString('Page 1 / 1 — 12', (string) $composant->set('parPageChoisi', 25)->render());
    }

    public function testActionDeGroupeMarqueLaSelectionCommeTraitee(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');
        $composant->set('selection', [(string) $this->toulouse->id(), (string) $this->foix->id()]);

        $html = (string) $composant->call('executerGroupe', ['cle' => 'signalements.traiter'])->render();

        self::assertStringContainsString('2 signalement(s) marqué(s) comme traité(s).', $html);

        // Rechargé depuis la base : Toulouse et Foix traités, Auch encore à traiter.
        $repository = self::getContainer()->get(SignalementRepository::class);
        self::assertInstanceOf(SignalementRepository::class, $repository);
        $etats = [];
        foreach ($repository->recents() as $signalement) {
            $etats[$signalement->adresse()] = $signalement->estTraite();
        }
        self::assertTrue($etats['Toulouse rue A']);
        self::assertTrue($etats['Foix chemin C']);
        self::assertFalse($etats['Auch place B']);
    }

    public function testUneActionDeGroupeNonAutoriseeEstRejetee(): void
    {
        $composant = $this->createLiveComponent('ListeSignalements');
        $composant->set('selection', [(string) $this->toulouse->id()]);

        $this->expectException(\InvalidArgumentException::class);

        $composant->call('executerGroupe', ['cle' => 'signalements.supprimer_tout']);
    }
}
