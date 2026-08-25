<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleUnH1;
use PHPUnit\Framework\TestCase;

final class RegleUnH1Test extends TestCase
{
    private function auditer(string $body): Gravite
    {
        $html = '<html><head></head><body>'.$body.'</body></html>';

        return (new RegleUnH1())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testUnSeulH1EstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer('<h1>Titre principal</h1><h2>Section</h2>'));
    }

    public function testAucunH1EstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<h2>Sous-titre sans titre</h2>'));
    }

    public function testPlusieursH1EstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<h1>Un</h1><h1>Deux</h1>'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('un-h1', (new RegleUnH1())->code());
    }
}
