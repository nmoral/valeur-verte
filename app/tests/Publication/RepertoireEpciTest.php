<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\RepertoireEpci;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le répertoire des EPCI lit ``data.epci`` (#366) en lecture
 * seule pour résoudre une URL SEO ``/valeur-verte/epci/{slug}`` (#363) vers son
 * EPCI (code SIREN + nom), et le sens inverse (code → EPCI, pour reconstruire
 * l'URL). Le slug n'est jamais stocké : il est recalculé depuis le nom par {@see
 * \App\Publication\Slug}.
 */
final class RepertoireEpciTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testResoutUnEpciParSonSlug(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');

        $epci = $this->repertoire()->resoudre('bordeaux-metropole');

        self::assertNotNull($epci);
        self::assertSame('243300316', $epci->codeEpci);
        self::assertSame('Bordeaux Métropole', $epci->nom);
    }

    public function testUnSlugInconnuNeResoutPas(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');

        self::assertNull($this->repertoire()->resoudre('narnia-agglomeration'));
    }

    public function testLeSensInverseParCode(): void
    {
        self::bootKernel();
        $this->semerEpci('200069193', 'Toulouse Métropole');

        $epci = $this->repertoire()->pour('200069193');

        self::assertNotNull($epci);
        self::assertSame('Toulouse Métropole', $epci->nom);
        self::assertSame('toulouse-metropole', $epci->slug());
    }

    public function testUnCodeInconnuNeDonneRien(): void
    {
        self::bootKernel();
        $this->prive();

        self::assertNull($this->repertoire()->pour('999999999'));
    }

    public function testSurUnHomonymeLePlusPetitCodeLEmporte(): void
    {
        // Désambiguïsation déterministe : deux EPCI au même nom (donc même slug) →
        // le plus petit code SIREN gagne (ORDER BY code_epci), jamais un aléa.
        self::bootKernel();
        $this->semerEpci('200000002', 'CC du Val');
        $this->semerEpci('200000001', 'CC du Val');

        $epci = $this->repertoire()->resoudre('cc-du-val');

        self::assertNotNull($epci);
        self::assertSame('200000001', $epci->codeEpci);
    }

    private function repertoire(): RepertoireEpci
    {
        return new RepertoireEpci($this->app());
    }
}
