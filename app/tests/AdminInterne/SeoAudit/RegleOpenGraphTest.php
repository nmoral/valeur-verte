<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleOpenGraph;
use PHPUnit\Framework\TestCase;

final class RegleOpenGraphTest extends TestCase
{
    private function auditer(string $head): Gravite
    {
        $html = '<html><head>'.$head.'</head><body></body></html>';

        return (new RegleOpenGraph())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testLesTroisBalisesPresentesEstOk(): void
    {
        $head = '<meta property="og:title" content="Valeur verte">'
            .'<meta property="og:type" content="website">'
            .'<meta name="twitter:card" content="summary">';

        self::assertSame(Gravite::Ok, $this->auditer($head));
    }

    public function testUneBaliseManquanteEstUneAlerte(): void
    {
        $head = '<meta property="og:title" content="Valeur verte">'
            .'<meta property="og:type" content="website">';

        self::assertSame(Gravite::Alerte, $this->auditer($head));
    }

    public function testBaliseVideCompteCommeManquante(): void
    {
        $head = '<meta property="og:title" content="">'
            .'<meta property="og:type" content="website">'
            .'<meta name="twitter:card" content="summary">';

        self::assertSame(Gravite::Alerte, $this->auditer($head));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('open-graph', (new RegleOpenGraph())->code());
    }
}
