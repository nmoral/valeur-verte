<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relance conversationnelle après le premier rapport : marqueur compte.email_premier_rapport_envoye_le — #109';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD email_premier_rapport_envoye_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP email_premier_rapport_envoye_le');
    }
}
