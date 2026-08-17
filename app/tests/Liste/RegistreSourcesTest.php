<?php

declare(strict_types=1);

namespace App\Tests\Liste;

use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\RegistreSources;
use App\Liste\SourceListe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RegistreSourcesTest extends TestCase
{
    public function testResoudUneSourceParSaCle(): void
    {
        $source = $this->sourceVide();
        $registre = new RegistreSources(new ServiceLocator(['comptes' => static fn (): SourceListe => $source]));

        self::assertSame($source, $registre->source('comptes'));
    }

    public function testUneCleInconnueEstRefusee(): void
    {
        $registre = new RegistreSources(new ServiceLocator([]));

        $this->expectException(\InvalidArgumentException::class);

        $registre->source('inexistante');
    }

    private function sourceVide(): SourceListe
    {
        return new class implements SourceListe {
            public function interroger(ParametresListe $parametres): PageListe
            {
                return PageListe::de([], 1, 50, 0);
            }

            public function colonnes(): ColonnesTriables
            {
                return ColonnesTriables::de('id');
            }
        };
    }
}
