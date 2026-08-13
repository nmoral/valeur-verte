<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Instantané de rapport immuable : colonne JSONB rapport.instantane — #33';
    }

    public function up(Schema $schema): void
    {
        // Défaut vide pour les rares lignes préexistantes (aucune en production) ;
        // toute nouvelle ligne écrit l'instantané complet à la création.
        $this->addSql("ALTER TABLE rapport ADD instantane JSONB NOT NULL DEFAULT '{}'::jsonb");
        $this->addSql('ALTER TABLE rapport ALTER instantane DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rapport DROP instantane');
    }
}
