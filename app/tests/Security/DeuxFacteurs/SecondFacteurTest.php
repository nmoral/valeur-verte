<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\SecondFacteur;
use PHPUnit\Framework\TestCase;

/**
 * Second facteur d'un compte (#312) : le secret partagé, son état (en attente
 * d'activation ou actif) et la mémoire du dernier pas consommé.
 *
 * Deux invariants portent toute la sécurité de l'objet :
 *
 * 1. **tant qu'il n'est pas activé, il ne vaut rien** — un enrôlement abandonné
 *    (onglet fermé après le scan) ne doit jamais laisser un compte à moitié
 *    protégé, ni surtout à moitié verrouillé ;
 * 2. **un pas déjà consommé ne repasse pas** — un code reste valide une trentaine
 *    de secondes ; sans cette mémoire, un code lu par-dessus l'épaule se rejoue
 *    dans la foulée.
 */
final class SecondFacteurTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testUnSecondFacteurNaitEnAttenteDActivation(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);

        self::assertFalse($facteur->estActif());
    }

    public function testIlNestActifQuUneFoisLEnrolementConfirme(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);

        $facteur->activer(new \DateTimeImmutable('2026-01-01 10:00:00'));

        self::assertTrue($facteur->estActif());
    }

    public function testUnPasNonEncoreConsommeEstAcceptable(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);

        self::assertTrue($facteur->pasEstConsommable(1000));
    }

    public function testLePasConsommeNeRepassePas(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);

        $facteur->consommerPas(1000);

        self::assertFalse($facteur->pasEstConsommable(1000));
    }

    public function testUnPasAnterieurAuDernierConsommeEstRefuse(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);
        $facteur->consommerPas(1000);

        // La tolérance de dérive accepte la fenêtre précédente : sans cette règle,
        // un code capté puis rejoué une fenêtre plus tôt passerait encore.
        self::assertFalse($facteur->pasEstConsommable(999));
        self::assertTrue($facteur->pasEstConsommable(1001));
    }

    public function testLeSecretResteLisiblePourRecalculerLeCode(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);

        // Il n'est pas comparé mais rejoué : le serveur recalcule le code du
        // téléphone. Le hacher le rendrait inutilisable.
        self::assertSame(self::SECRET, $facteur->secret());
        self::assertSame(42, $facteur->userId());
    }
}
