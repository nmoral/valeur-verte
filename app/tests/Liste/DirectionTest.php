<?php

declare(strict_types=1);

namespace App\Tests\Liste;

use App\Liste\Direction;
use PHPUnit\Framework\TestCase;

final class DirectionTest extends TestCase
{
    public function testDepuisReconnaitAscEtDesc(): void
    {
        self::assertSame(Direction::Asc, Direction::depuis('asc'));
        self::assertSame(Direction::Desc, Direction::depuis('desc'));
    }

    public function testDepuisNormaliseCasseEtEspaces(): void
    {
        self::assertSame(Direction::Desc, Direction::depuis('  DESC '));
        self::assertSame(Direction::Asc, Direction::depuis('Asc'));
    }

    public function testDepuisRetombeSurAscQuandInvalide(): void
    {
        self::assertSame(Direction::Asc, Direction::depuis(''));
        self::assertSame(Direction::Asc, Direction::depuis('n_importe_quoi'));
    }

    public function testInverseBascule(): void
    {
        self::assertSame(Direction::Desc, Direction::Asc->inverse());
        self::assertSame(Direction::Asc, Direction::Desc->inverse());
    }

    public function testVersSql(): void
    {
        self::assertSame('ASC', Direction::Asc->versSql());
        self::assertSame('DESC', Direction::Desc->versSql());
    }
}
