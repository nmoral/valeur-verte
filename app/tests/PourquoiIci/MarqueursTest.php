<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\Marqueurs;
use PHPUnit\Framework\TestCase;

/**
 * Les marqueurs d'une zone, indexés par nom (#341, Lot 3).
 *
 * Vue en lecture des lignes de `data.marqueurs` d'une commune ou d'un département
 * (contextuels ET mesurés confondus) : le moteur y interroge une valeur ou teste
 * une condition. La provenance (DBAL) est un détail du Lot 4.
 */
final class MarqueursTest extends TestCase
{
    public function testRendLaValeurDUnMarqueurConnu(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'marche' => 'detendu']);

        self::assertSame('froid', $marqueurs->valeur('climat'));
    }

    public function testValeurAbsenteEstNull(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid']);

        self::assertNull($marqueurs->valeur('littoral'));
    }

    public function testTesteUneCondition(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        self::assertTrue($marqueurs->a('climat', 'froid'));
        self::assertFalse($marqueurs->a('climat', 'doux'));
        self::assertFalse($marqueurs->a('littoral', 'oui')); // marqueur absent
    }
}
