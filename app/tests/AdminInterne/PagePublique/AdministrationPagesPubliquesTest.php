<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\PagePublique;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\AdminInterne\PagePublique\AdministrationPagesPubliques;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Kill switch manuel de l'admin interne (#268, épic #6).
 *
 * L'escalade automatique coupe une page au-delà du seuil critique ; il faut aussi
 * pouvoir couper **à la main**, sans attendre un seuil : abus signalé par un tiers,
 * contenu litigieux, pro injoignable. La décision est tracée au journal d'audit
 * (#50) — un kill switch anonyme n'est pas un kill switch, c'est une panne.
 *
 * Les deux transitions sont **idempotentes** : recliquer sur « suspendre » ne
 * réécrit pas la date de coupure ni ne re-trace.
 */
final class AdministrationPagesPubliquesTest extends KernelTestCase
{
    private const string AUTEUR = 'admin@example.com';

    public function testSuspendreRetireLaPageDuServiceEtTrace(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        self::assertTrue($this->administration()->suspendre($page->token(), self::AUTEUR));

        $this->viderIdentityMap();
        self::assertNull($this->pages()->trouverActiveParJeton($page->token()));
        $evenement = $this->dernierEvenement('page_publique.suspendue');
        self::assertNotNull($evenement, 'La suspension doit être tracée au journal d\'audit.');
        self::assertSame(self::AUTEUR, $evenement->acteur());
        self::assertSame($page->token(), $evenement->cible());
    }

    public function testSuspendreUnePageDejaSuspendueNeFaitRien(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->administration()->suspendre($page->token(), self::AUTEUR);
        $this->viderIdentityMap();

        self::assertFalse($this->administration()->suspendre($page->token(), self::AUTEUR));
    }

    public function testReactiverRemetLaPageEnService(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->administration()->suspendre($page->token(), self::AUTEUR);
        $this->viderIdentityMap();

        self::assertTrue($this->administration()->reactiver($page->token(), self::AUTEUR));

        $this->viderIdentityMap();
        self::assertNotNull($this->pages()->trouverActiveParJeton($page->token()));
        self::assertNotNull($this->dernierEvenement('page_publique.reactivee'));
    }

    public function testReactiverUnePageEnServiceNeFaitRien(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        self::assertFalse($this->administration()->reactiver($page->token(), self::AUTEUR));
    }

    public function testUnJetonInconnuNeFaitRien(): void
    {
        self::bootKernel();

        self::assertFalse($this->administration()->suspendre('jeton-inconnu', self::AUTEUR));
        self::assertFalse($this->administration()->reactiver('jeton-inconnu', self::AUTEUR));
    }

    private function administration(): AdministrationPagesPubliques
    {
        $administration = static::getContainer()->get(AdministrationPagesPubliques::class);
        self::assertInstanceOf(AdministrationPagesPubliques::class, $administration);

        return $administration;
    }

    private function dernierEvenement(string $action): ?\App\AdminInterne\JournalAudit\EvenementAudit
    {
        $journal = static::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        foreach ($journal->evenementsRecents(20) as $evenement) {
            if ($action === $evenement->action()) {
                return $evenement;
            }
        }

        return null;
    }

    private function pages(): PagePubliqueRepository
    {
        $pages = static::getContainer()->get(PagePubliqueRepository::class);
        self::assertInstanceOf(PagePubliqueRepository::class, $pages);

        return $pages;
    }

    private function viderIdentityMap(): void
    {
        $this->em()->clear();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function creerPage(): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }
}
