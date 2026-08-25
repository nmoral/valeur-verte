<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleDonneesStructurees;
use PHPUnit\Framework\TestCase;

final class RegleDonneesStructureesTest extends TestCase
{
    private function auditer(string $head): Gravite
    {
        $html = '<html><head>'.$head.'</head><body></body></html>';

        return (new RegleDonneesStructurees())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testUnJsonLdValideAvecContexteEtTypeEstOk(): void
    {
        $jsonLd = '{"@context":"https://schema.org","@type":"RealEstateAgent","name":"Valeur verte"}';

        self::assertSame(Gravite::Ok, $this->auditer('<script type="application/ld+json">'.$jsonLd.'</script>'));
    }

    public function testAucunJsonLdEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer(''));
    }

    public function testJsonLdIllisibleEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<script type="application/ld+json">{ pas du json ,}</script>'));
    }

    public function testJsonLdSansContexteNiTypeEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<script type="application/ld+json">{"name":"sans contexte"}</script>'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('donnees-structurees', (new RegleDonneesStructurees())->code());
    }
}
