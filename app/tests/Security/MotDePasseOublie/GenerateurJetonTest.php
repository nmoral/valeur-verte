<?php

declare(strict_types=1);

namespace App\Tests\Security\MotDePasseOublie;

use App\Security\MotDePasseOublie\GenerateurJeton;
use PHPUnit\Framework\TestCase;

/**
 * Génération du jeton de réinitialisation (#194). Le jeton brut, imprévisible,
 * part dans l'URL de l'email ; seule son empreinte SHA-256 est stockée. La
 * vérification se fait en re-hachant le jeton reçu et en comparant les empreintes.
 */
final class GenerateurJetonTest extends TestCase
{
    public function testLeJetonBrutEstImprevisibleEtNonVide(): void
    {
        $generateur = new GenerateurJeton();

        $a = $generateur->jetonBrut();
        $b = $generateur->jetonBrut();

        self::assertNotSame('', $a);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testLEmpreinteEstLeSha256DuJeton(): void
    {
        $generateur = new GenerateurJeton();

        self::assertSame(hash('sha256', 'un-jeton'), $generateur->empreinte('un-jeton'));
    }
}
