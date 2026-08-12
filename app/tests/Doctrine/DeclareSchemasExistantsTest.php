<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\DeclareSchemasExistants;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeclareSchemasExistantsTest extends KernelTestCase
{
    public function testDeclareLesSchemasDejaPresentsEnBaseDansLeSchemaCible(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        // Schéma cible « vide » : sans le listener, le diff voudrait créer app/data.
        $cible = new Schema();
        self::assertFalse($cible->hasNamespace('app'));

        (new DeclareSchemasExistants())->postGenerateSchema(new GenerateSchemaEventArgs($manager, $cible));

        self::assertTrue($cible->hasNamespace('app'), 'le schéma app doit être déclaré');
        self::assertTrue($cible->hasNamespace('data'), 'le schéma data doit être déclaré');
    }
}
