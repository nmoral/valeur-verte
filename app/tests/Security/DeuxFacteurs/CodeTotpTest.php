<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodeTotp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Code TOTP (RFC 6238) du second facteur de l'admin interne (#312).
 *
 * Écrit à la main plutôt qu'emprunté : l'algorithme tient en quelques lignes
 * (HMAC-SHA1 du compteur de temps, troncature dynamique) et la RFC publie ses
 * **vecteurs de test**, qui sont la meilleure spécification exécutable qui soit.
 * Une dépendance de plus dans le chemin d'authentification se justifierait par
 * un algorithme qu'on ne saurait pas vérifier ; ce n'est pas le cas ici.
 */
final class CodeTotpTest extends TestCase
{
    // Secret des vecteurs RFC 6238 : « 12345678901234567890 » en ASCII, encodé
    // en base32 (c'est sous cette forme que le secret voyage vers l'application
    // d'authentification).
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * Les vecteurs de la RFC sont donnés en 8 chiffres ; un code à 6 chiffres en
     * est le suffixe (même troncature, modulo plus petit).
     */
    #[DataProvider('vecteursRfc6238')]
    public function testLesVecteursDeLaRfcSontReproduits(int $horodatage, string $codeAttendu): void
    {
        $code = new CodeTotp(self::SECRET_RFC);

        self::assertSame($codeAttendu, $code->pour(self::instant($horodatage)));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function vecteursRfc6238(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
    }

    public function testLeCodeChangeAChaquePasDeTrenteSecondes(): void
    {
        $code = new CodeTotp(self::SECRET_RFC);

        // 59 et 89 sont dans deux pas différents (pas de 30 s).
        self::assertNotSame($code->pour(self::instant(59)), $code->pour(self::instant(89)));
        // 60 et 89 sont dans le même pas : même code.
        self::assertSame($code->pour(self::instant(60)), $code->pour(self::instant(89)));
    }

    public function testLePasCourantIdentifieLaFenetreDeTrenteSecondes(): void
    {
        $code = new CodeTotp(self::SECRET_RFC);

        // Le pas est ce qu'on mémorise pour refuser un rejeu : deux instants du
        // même pas doivent rendre le même numéro, faute de quoi un code intercepté
        // resterait utilisable une seconde fois dans sa fenêtre.
        self::assertSame(2, $code->pas(self::instant(60)));
        self::assertSame(2, $code->pas(self::instant(89)));
        self::assertSame(3, $code->pas(self::instant(90)));
    }

    public function testUnSecretQuiNestPasDuBase32EstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CodeTotp('pas du base32 !');
    }

    private static function instant(int $horodatage): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$horodatage);
    }
}
