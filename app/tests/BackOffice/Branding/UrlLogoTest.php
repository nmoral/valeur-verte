<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\UrlLogo;
use PHPUnit\Framework\TestCase;

/**
 * Validation anti-SSRF/XSS de l'URL de logo du branding tenant (#267, épic #6).
 *
 * La page publique étant hébergée sur notre origine, l'URL de logo configurée par
 * le pro est une frontière : on n'accepte qu'un chemin same-origin (`/…`, logo
 * hébergé chez nous) ou une URL absolue `https://` vers un hôte public — jamais
 * `http://`, un autre schéma, ni une adresse privée/loopback/link-local (ex. le
 * point de métadonnées cloud `169.254.169.254`).
 */
final class UrlLogoTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsAcceptees(): iterable
    {
        yield 'chemin same-origin' => ['/uploads/logo.png'];
        yield 'https hôte public' => ['https://cdn.exemple.fr/logo.png'];
        yield 'https avec port' => ['https://cdn.exemple.fr:8443/logo.png'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsRejetees(): iterable
    {
        yield 'http non chiffré' => ['http://cdn.exemple.fr/logo.png'];
        yield 'schéma javascript' => ['javascript:alert(1)'];
        yield 'schéma data' => ['data:image/png;base64,AAAA'];
        yield 'protocol-relative' => ['//cdn.exemple.fr/logo.png'];
        yield 'loopback' => ['https://127.0.0.1/logo.png'];
        yield 'localhost' => ['https://localhost/logo.png'];
        yield 'privé 10/8' => ['https://10.0.0.5/logo.png'];
        yield 'privé 192.168' => ['https://192.168.1.10/logo.png'];
        yield 'link-local métadonnées' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'loopback ipv6' => ['https://[::1]/logo.png'];
        yield 'chaîne vide' => [''];
        yield 'pas une url' => ['n%import@quoi'];
    }

    /**
     * @dataProvider urlsAcceptees
     */
    public function testUneUrlAcceptableNeLeveRien(string $url): void
    {
        UrlLogo::valider($url);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @dataProvider urlsRejetees
     */
    public function testUneUrlDangereuseEstRejetee(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        UrlLogo::valider($url);
    }
}
