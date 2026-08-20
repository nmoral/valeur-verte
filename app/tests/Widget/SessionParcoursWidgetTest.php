<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\SessionParcoursWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identifiant de session du parcours widget (#90, épic #6).
 *
 * Le tracking du parcours est **anonyme par défaut** : l'identifiant qui relie
 * les étapes d'une même visite est tiré au hasard, éphémère, et ne porte
 * **aucune donnée personnelle** — ni IP, ni email, ni empreinte de navigateur.
 * Il n'a de sens que le temps d'une visite : deux visites, deux identifiants.
 *
 * Format contraint (32 caractères hexadécimaux) pour que l'endpoint public de
 * collecte puisse écarter sans ambiguïté ce qui n'en est pas un.
 */
final class SessionParcoursWidgetTest extends TestCase
{
    public function testUnIdentifiantEmisEstValide(): void
    {
        $sessions = new SessionParcoursWidget();

        self::assertTrue($sessions->estValide($sessions->demarrer()));
    }

    public function testDeuxVisitesNePartagentPasLeMemeIdentifiant(): void
    {
        $sessions = new SessionParcoursWidget();

        self::assertNotSame($sessions->demarrer(), $sessions->demarrer());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiantsInvalides(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => [str_repeat('a', 31)];
        yield 'trop long' => [str_repeat('a', 33)];
        yield 'hors hexadécimal' => [str_repeat('a', 31).'z'];
        yield 'majuscules' => [str_repeat('A', 32)];
    }

    #[DataProvider('identifiantsInvalides')]
    public function testUnIdentifiantMalformeEstRejete(string $identifiant): void
    {
        $sessions = new SessionParcoursWidget();

        self::assertFalse($sessions->estValide($identifiant));
    }
}
