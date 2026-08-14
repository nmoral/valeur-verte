<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use App\Compte\Formule;
use App\Compte\StatutCandidatureFondateur;
use PHPUnit\Framework\TestCase;

/**
 * Compte (agrégat de facturation, distinct de l'utilisateur — un compte pourra
 * porter plusieurs utilisateurs plus tard). À la création : formule Classique et
 * candidature fondateur « en attente », que l'éditeur valide après contact (#61).
 */
final class CompteTest extends TestCase
{
    public function testUnCompteNeufEstClassiqueAvecCandidatureEnAttente(): void
    {
        $compte = Compte::creer(42, new \DateTimeImmutable('2026-02-01 09:00:00'));

        self::assertSame(42, $compte->tenantId());
        self::assertSame(Formule::Classique, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::EnAttente, $compte->candidatureFondateur());
    }

    public function testValiderLaCandidatureBasculeEnFondateur(): void
    {
        $compte = Compte::creer(42, new \DateTimeImmutable('2026-02-01 09:00:00'));

        $compte->validerCommeFondateur();

        self::assertSame(Formule::Fondateur, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::Validee, $compte->candidatureFondateur());
    }

    public function testRefuserLaCandidatureLaisseLeCompteClassique(): void
    {
        $compte = Compte::creer(42, new \DateTimeImmutable('2026-02-01 09:00:00'));

        $compte->refuserFondateur();

        self::assertSame(Formule::Classique, $compte->formule());
        self::assertSame(StatutCandidatureFondateur::Refusee, $compte->candidatureFondateur());
    }
}
