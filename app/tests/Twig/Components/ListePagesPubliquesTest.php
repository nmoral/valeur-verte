<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Kill switch des pages publiques tel qu'on l'actionne (#268, épic #6).
 *
 * L'écran d'admin n'est pas décoratif : c'est de là que part la coupure. On exerce
 * donc l'action de groupe elle-même — sélection, exécution, effet réel sur le
 * service de la page — et pas seulement le rendu du tableau.
 */
final class ListePagesPubliquesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private PagePublique $durand;
    private PagePublique $martin;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->durand = $this->creerPage('agence-durand');
        $this->martin = $this->creerPage('agence-martin');
    }

    public function testLeRenduMontreChaquePageEtSonEtatDeService(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');

        $html = (string) $composant->render();

        self::assertStringContainsString('agence-durand', $html);
        self::assertStringContainsString('agence-martin', $html);
        self::assertStringContainsString('En service', $html);
    }

    public function testLActionDeGroupeCoupeLaSelection(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');
        $composant->set('selection', [$this->durand->token()]);

        $html = (string) $composant->call('executerGroupe', ['cle' => 'pages_publiques.suspendre'])->render();

        self::assertStringContainsString('1 page(s) suspendue(s).', $html);
        self::assertNull($this->pages()->trouverActiveParJeton($this->durand->token()), 'La page coupée ne se résout plus.');
        self::assertNotNull($this->pages()->trouverActiveParJeton($this->martin->token()), 'La page non sélectionnée reste en service.');
        self::assertStringContainsString('Suspendue', $html);
    }

    public function testLaCoupureDepuisLEcranEstTracee(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');
        $composant->set('selection', [$this->durand->token()]);

        $composant->call('executerGroupe', ['cle' => 'pages_publiques.suspendre']);

        $journal = static::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);
        $actions = array_map(
            static fn ($evenement): string => $evenement->action(),
            $journal->evenementsRecents(20),
        );
        self::assertContains('page_publique.suspendue', $actions);
    }

    public function testLActionDeGroupeRetablitLaSelection(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');
        $composant->set('selection', [$this->durand->token()]);
        $composant->call('executerGroupe', ['cle' => 'pages_publiques.suspendre']);

        $composant->set('selection', [$this->durand->token()]);
        $html = (string) $composant->call('executerGroupe', ['cle' => 'pages_publiques.reactiver'])->render();

        self::assertStringContainsString('1 page(s) rétablie(s).', $html);
        self::assertNotNull($this->pages()->trouverActiveParJeton($this->durand->token()));
    }

    public function testLaRechercheTrouveUnePageParSonJeton(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');

        $html = (string) $composant->set('recherche', $this->martin->token())->render();

        self::assertStringContainsString('agence-martin', $html);
        self::assertStringNotContainsString('agence-durand', $html);
    }

    public function testUneActionNonAutoriseeEstRejetee(): void
    {
        $composant = $this->createLiveComponent('ListePagesPubliques');
        $composant->set('selection', [$this->durand->token()]);

        $this->expectException(\InvalidArgumentException::class);

        $composant->call('executerGroupe', ['cle' => 'pages_publiques.supprimer']);
    }

    private function pages(): PagePubliqueRepository
    {
        $pages = static::getContainer()->get(PagePubliqueRepository::class);
        self::assertInstanceOf(PagePubliqueRepository::class, $pages);

        return $pages;
    }

    private function creerPage(string $slug): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), $slug);
        $em->persist($page);
        $em->flush();

        return $page;
    }
}
