<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleAltImages;
use PHPUnit\Framework\TestCase;

final class RegleAltImagesTest extends TestCase
{
    private function auditer(string $body): Gravite
    {
        $html = '<html><head></head><body>'.$body.'</body></html>';

        return (new RegleAltImages())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testToutesLesImagesAvecAltEstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer('<img src="a.png" alt="Un graphique"><img src="b.png" alt="">'));
    }

    public function testAucuneImageEstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer('<p>Pas d\'image</p>'));
    }

    public function testUneImageSansAttributAltEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<img src="a.png" alt="ok"><img src="b.png">'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('alt-images', (new RegleAltImages())->code());
    }
}
