<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\Formule;
use App\Compte\ParametresFormule;
use App\Compte\PlafondFondateursAtteint;
use App\Compte\ReglagesFormuleRepository;
use App\Compte\StatutCandidatureFondateur;
use App\Compte\ValidationCandidatureFondateur;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validation d'une candidature fondateur par l'éditeur (#61) : après contact, il
 * valide (bascule en Fondateur) ou refuse (reste Classique). La formule Fondateur
 * est plafonnée : au-delà, la validation est refusée.
 */
final class ValidationCandidatureFondateurTest extends KernelTestCase
{
    private ValidationCandidatureFondateur $validation;
    private CompteRepository $comptes;

    protected function setUp(): void
    {
        self::bootKernel();
        $validation = self::getContainer()->get(ValidationCandidatureFondateur::class);
        self::assertInstanceOf(ValidationCandidatureFondateur::class, $validation);
        $this->validation = $validation;
        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $this->comptes = $comptes;
    }

    public function testValiderBasculeLeCompteEnFondateur(): void
    {
        $this->comptes->enregistrer(Compte::creer(9001, new \DateTimeImmutable('2026-02-01 09:00:00')));

        $this->validation->valider(9001);

        $compte = $this->comptes->pourTenant(9001);
        self::assertNotNull($compte);
        self::assertSame(Formule::Fondateur, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::Validee, $compte->candidatureFondateur());
    }

    public function testRefuserLaisseLeCompteClassique(): void
    {
        $this->comptes->enregistrer(Compte::creer(9002, new \DateTimeImmutable('2026-02-01 09:00:00')));

        $this->validation->refuser(9002);

        $compte = $this->comptes->pourTenant(9002);
        self::assertNotNull($compte);
        self::assertSame(Formule::Classique, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::Refusee, $compte->candidatureFondateur());
    }

    public function testLePlafondDeFondateursBloqueLaValidation(): void
    {
        // Plafond ramené à 1 pour le test : un seul fondateur possible.
        $reglages = self::getContainer()->get(ReglagesFormuleRepository::class);
        self::assertInstanceOf(ReglagesFormuleRepository::class, $reglages);
        $reglages->enregistrer(Formule::Fondateur, new ParametresFormule(180, 100, 1));

        $this->comptes->enregistrer(Compte::creer(9003, new \DateTimeImmutable('2026-02-01 09:00:00')));
        $this->comptes->enregistrer(Compte::creer(9004, new \DateTimeImmutable('2026-02-01 09:00:00')));
        $this->validation->valider(9003);

        $this->expectException(PlafondFondateursAtteint::class);

        try {
            $this->validation->valider(9004);
        } finally {
            $refuse = $this->comptes->pourTenant(9004);
            self::assertNotNull($refuse);
            self::assertSame(Formule::Classique, $refuse->formule(), 'Le compte de trop reste Classique.');
        }
    }
}
