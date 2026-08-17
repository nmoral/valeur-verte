<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'État de traitement des signalements : signalement.traite_le — #49 (action de groupe « marquer traité »)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signalement ADD traite_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signalement DROP traite_le');
    }
}
