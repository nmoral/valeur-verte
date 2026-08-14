<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Formule;
use App\Compte\StatutCandidatureFondateur;
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

        $user = $createur->creer('compte@example.com', 'motdepasse-solide');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $compte = $comptes->pourTenant($tenantId);

        self::assertInstanceOf(Compte::class, $compte);
        self::assertSame(Formule::Classique, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::EnAttente, $compte->candidatureFondateur());
    }
}
