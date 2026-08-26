<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\EmpreinteEffacement;
use App\Compte\Formule;
use App\Compte\StatutCandidatureFondateur;
use App\Compte\TraceEffacement;
use App\Compte\TraceEffacementRepository;
use App\Security\AdresseEmail;
use App\Security\CreateurDeCompte;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * À la création d'un compte pro, l'agrégat Compte est frappé pour le tenant :
 * formule Classique, candidature fondateur en attente (#61). C'est le porteur des
 * droits d'essai, distinct de l'utilisateur.
 */
final class CreationCompteAInscriptionTest extends WebTestCase
{
    public function testLInscriptionFrappeUnCompteClassiqueEnAttente(): void
    {
        self::bootKernel();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);

        $user = $createur->creer('compte@example.com', 'motdepasse-solide', '2026-08-16');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $compte = $comptes->pourTenant($tenantId);

        self::assertInstanceOf(Compte::class, $compte);
        self::assertSame(Formule::Classique, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::EnAttente, $compte->candidatureFondateur());
    }

    public function testUneAdresseSansTraceDEffacementGardeSonEssai(): void
    {
        self::bootKernel();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);

        $user = $createur->creer('neuf@example.com', 'motdepasse-solide', '2026-08-16');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $compte = $this->comptes()->pourTenant($tenantId);
        self::assertInstanceOf(Compte::class, $compte);
        self::assertNull($compte->essaiNeutraliseLe(), 'une adresse jamais effacée garde son essai');
    }

    public function testUneAdresseAvecTraceDEffacementNaitSansNouvelEssai(): void
    {
        self::bootKernel();
        $empreintes = self::getContainer()->get(EmpreinteEffacement::class);
        self::assertInstanceOf(EmpreinteEffacement::class, $empreintes);
        $canonique = AdresseEmail::depuis('recree@example.com')->canonique();
        $this->traces()->enregistrer(TraceEffacement::creer(
            $empreintes->pour($canonique),
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            5,
            new \DateTimeImmutable('2026-03-01 00:00:00'),
        ));

        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer('recree@example.com', 'motdepasse-solide', '2026-08-16');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $compte = $this->comptes()->pourTenant($tenantId);
        self::assertInstanceOf(Compte::class, $compte);
        self::assertNotNull($compte->essaiNeutraliseLe(), 'une adresse déjà effacée ne rouvre pas d\'essai');
    }

    public function testUneAdresseJetableNaitSansEssai(): void
    {
        self::bootKernel();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);

        // Aucune trace d'effacement : c'est le domaine jetable, pas la réinscription,
        // qui neutralise l'essai (#343, filet complémentaire du mur du 2ᵉ rapport).
        $user = $createur->creer('bob@yopmail.com', 'motdepasse-solide', '2026-08-16');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $compte = $this->comptes()->pourTenant($tenantId);
        self::assertInstanceOf(Compte::class, $compte);
        self::assertNotNull($compte->essaiNeutraliseLe(), 'une adresse jetable naît sans essai');
    }

    private function comptes(): CompteRepository
    {
        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);

        return $comptes;
    }

    private function traces(): TraceEffacementRepository
    {
        $traces = self::getContainer()->get(TraceEffacementRepository::class);
        self::assertInstanceOf(TraceEffacementRepository::class, $traces);

        return $traces;
    }
}
