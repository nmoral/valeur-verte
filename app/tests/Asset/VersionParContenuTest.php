<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use App\Asset\VersionParContenu;
use PHPUnit\Framework\TestCase;

final class VersionParContenuTest extends TestCase
{
    private string $repertoire;

    protected function setUp(): void
    {
        $this->repertoire = sys_get_temp_dir().'/vv-assets-'.uniqid();
        mkdir($this->repertoire);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->repertoire.'/*') ?: []);
        rmdir($this->repertoire);
    }

    public function testAppliqueUneVersionAUnFichierExistant(): void
    {
        file_put_contents($this->repertoire.'/app.css', 'body{}');

        $applique = (new VersionParContenu($this->repertoire))->applyVersion('app.css');

        self::assertMatchesRegularExpression('#^app\.css\?v=[0-9a-f]+$#', $applique);
    }

    public function testUnFichierInexistantResteInchange(): void
    {
        self::assertSame('css/absent.css', (new VersionParContenu($this->repertoire))->applyVersion('css/absent.css'));
    }

    public function testLaVersionChangeQuandLeContenuChange(): void
    {
        file_put_contents($this->repertoire.'/app.css', 'body{color:red}');
        $avant = (new VersionParContenu($this->repertoire))->getVersion('app.css');

        file_put_contents($this->repertoire.'/app.css', 'body{color:blue}');
        $apres = (new VersionParContenu($this->repertoire))->getVersion('app.css');

        self::assertNotSame('', $avant);
        self::assertNotSame($avant, $apres);
    }

    public function testUneQueryExistanteEstPreservee(): void
    {
        file_put_contents($this->repertoire.'/app.css', 'body{}');

        $applique = (new VersionParContenu($this->repertoire))->applyVersion('app.css?theme=sombre');

        self::assertStringContainsString('app.css?theme=sombre&v=', $applique);
    }
}
