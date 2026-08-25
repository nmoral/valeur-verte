<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleMetaDescription;
use PHPUnit\Framework\TestCase;

final class RegleMetaDescriptionTest extends TestCase
{
    private function auditer(string $head): Gravite
    {
        $html = '<html><head>'.$head.'</head><body></body></html>';

        return (new RegleMetaDescription())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testUneDescriptionDeBonneLongueurEstOk(): void
    {
        $contenu = str_repeat('a', RegleMetaDescription::LONGUEUR_MIN + 5);

        self::assertSame(Gravite::Ok, $this->auditer('<meta name="description" content="'.$contenu.'">'));
    }

    public function testDescriptionAbsenteEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer(''));
    }

    public function testDescriptionVideEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<meta name="description" content="   ">'));
    }

    public function testDescriptionTropCourteEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<meta name="description" content="trop court">'));
    }

    public function testDescriptionTropLongueEstUneAlerte(): void
    {
        $contenu = str_repeat('a', RegleMetaDescription::LONGUEUR_MAX + 1);

        self::assertSame(Gravite::Alerte, $this->auditer('<meta name="description" content="'.$contenu.'">'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('meta-description', (new RegleMetaDescription())->code());
    }
}
