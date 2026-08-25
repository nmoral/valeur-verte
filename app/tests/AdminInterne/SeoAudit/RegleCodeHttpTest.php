<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleCodeHttp;
use PHPUnit\Framework\TestCase;

final class RegleCodeHttpTest extends TestCase
{
    private function auditer(int $code): Gravite
    {
        return (new RegleCodeHttp())->evaluer(new PageAuditee('https://exemple.test/', $code, ''))->gravite;
    }

    public function testDeuxCentsEstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer(200));
    }

    public function testUneRedirectionEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer(301));
    }

    public function testUn404EstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer(404));
    }

    public function testUn500EstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer(500));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('code-http', (new RegleCodeHttp())->code());
    }
}
