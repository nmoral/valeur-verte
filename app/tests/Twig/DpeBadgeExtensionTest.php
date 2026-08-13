<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DpeBadgeExtension;
use PHPUnit\Framework\TestCase;

/**
 * Étiquette DPE « flèche énergie » du PDF (#32) : une image SVG inline à la
 * couleur officielle de la classe. La couleur et l'encre (contraste) sont
 * portées par l'extension, seule source de l'échelle DPE côté PDF.
 */
final class DpeBadgeExtensionTest extends TestCase
{
    public function testLeBadgePorteLaCouleurEtLaLettreDeLaClasse(): void
    {
        $svg = $this->svg('E');

        self::assertStringContainsString('#EDA53B', $svg);
        self::assertStringContainsString('>E</text>', $svg);
    }

    public function testUnFondFonceUtiliseUneEncreBlanche(): void
    {
        $svg = $this->svg('A');

        self::assertStringContainsString('#2E9B4F', $svg);
        self::assertStringContainsStringIgnoringCase('#ffffff', $svg);
    }

    public function testUnFondClairUtiliseUneEncreSombre(): void
    {
        $svg = $this->svg('C');

        self::assertStringContainsString('#8CC03A', $svg);
        self::assertStringContainsString('#16160F', $svg);
    }

    public function testLeBadgeEstUneImageSvgInline(): void
    {
        $html = (new DpeBadgeExtension())->badge('C');

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('data:image/svg+xml;base64,', $html);
    }

    private function svg(string $lettre): string
    {
        $html = (new DpeBadgeExtension())->badge($lettre);
        if (1 !== preg_match('/base64,([^"]+)"/', $html, $correspondance)) {
            self::fail('Le badge doit exposer une image SVG en base64.');
        }

        return base64_decode($correspondance[1], true) ?: '';
    }
}
