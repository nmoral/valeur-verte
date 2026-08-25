<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\MurVerificationEmail;
use App\Security\User;
use App\Tenant\TenantContext;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Mur de vérification d'email (#333) : au-delà du premier rapport, produire exige
 * une adresse vérifiée. Le premier rapport reste libre (ONB-4 intact). Un blocage
 * est mesuré une seule fois par pro (événement au journal d'audit).
 */
final class MurVerificationEmailTest extends KernelTestCase
{
    private JournalAuditEnMemoire $journal;
    private MurVerificationEmail $mur;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->journal = new JournalAuditEnMemoire();
        $this->mur = new MurVerificationEmail(
            $this->repo(CompteRepository::class),
            $this->repo(CompteurRapportsConsommes::class),
            $this->tenant(),
            $this->journal,
            new MockClock(new \DateTimeImmutable('2026-08-25 10:00:00')),
        );
    }

    public function testIlNeBloquePasUnEmailVerifie(): void
    {
        $this->tenant()->definir($this->semerCompteAvecRapports(3));

        self::assertFalse($this->mur->bloqueLaProduction($this->utilisateur(verifie: true)));
        self::assertCount(0, $this->journal->evenementsRecents());
    }

    public function testIlNeBloquePasLePremierRapport(): void
    {
        // Aucun rapport encore produit : le tout premier reste libre (ONB-4).
        $this->tenant()->definir($this->semerCompteAvecRapports(0));

        self::assertFalse($this->mur->bloqueLaProduction($this->utilisateur(verifie: false)));
        self::assertCount(0, $this->journal->evenementsRecents());
    }

    public function testIlBloqueAuDelaDuPremierRapportSansVerificationEtLeMesureUneFois(): void
    {
        $this->tenant()->definir($this->semerCompteAvecRapports(1));
        $utilisateur = $this->utilisateur(verifie: false);

        self::assertTrue($this->mur->bloqueLaProduction($utilisateur));
        self::assertTrue($this->mur->bloqueLaProduction($utilisateur));

        $actions = array_map(
            static fn (EvenementAudit $e): string => $e->action(),
            $this->journal->evenementsRecents(),
        );
        self::assertSame(['rapport.verification_requise'], $actions, 'le blocage n\'est mesuré qu\'une fois');
    }

    private function utilisateur(bool $verifie): User
    {
        $user = new User('mur@example.com', ['ROLE_PRO']);
        if ($verifie) {
            $user->marquerEmailVerifie(new \DateTimeImmutable('2026-08-24 09:00:00'));
        }

        return $user;
    }

    private function semerCompteAvecRapports(int $nbRapports): int
    {
        $tenantId = $this->repo(TenantRepository::class)->nouveau()->id();
        self::assertNotNull($tenantId);
        $this->repo(CompteRepository::class)->enregistrer(Compte::creer($tenantId, new \DateTimeImmutable('2026-01-01')));

        for ($i = 0; $i < $nbRapports; ++$i) {
            $rapport = Rapport::creer(
                \sprintf('REF-%d-%d', $tenantId, $i),
                new \DateTimeImmutable('2026-08-01 09:00:00'),
                Source::Audit,
                InstantaneRapportMother::creer(adresse: 'Baziège'),
                \sprintf('/rapports/%d-%d.pdf', $tenantId, $i),
            );
            $rapport->setTenantId($tenantId);
            $this->repo(RapportRepository::class)->enregistrer($rapport);
        }

        return $tenantId;
    }

    private function tenant(): TenantContext
    {
        $contexte = self::getContainer()->get(TenantContext::class);
        self::assertInstanceOf(TenantContext::class, $contexte);

        return $contexte;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function repo(string $classe): object
    {
        $service = self::getContainer()->get($classe);
        self::assertInstanceOf($classe, $service);

        return $service;
    }
}
