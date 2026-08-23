<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RGPD : date de demande d\'effacement d\'un compte (délai de grâce) — compte.suppression_demandee_le — #308';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD suppression_demandee_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP suppression_demandee_le');
    }
}
