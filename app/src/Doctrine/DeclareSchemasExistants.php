<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Déclare dans le schéma cible tous les schémas déjà présents en base
 * (app, data, public, postgis…) pour que `doctrine:migrations:diff` ne génère
 * pas de « CREATE SCHEMA » superflu — quirk Doctrine + PostgreSQL multi-schéma.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DeclareSchemasExistants
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $schemaManager = $args->getEntityManager()->getConnection()->createSchemaManager();

        foreach ($schemaManager->listSchemaNames() as $nom) {
            if (!$schema->hasNamespace($nom)) {
                $schema->createNamespace($nom);
            }
        }
    }
}
