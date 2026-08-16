<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acceptation des conditions à l\'inscription : utilisateur.conditions_acceptees_version/le — #228';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD conditions_acceptees_version VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur ADD conditions_acceptees_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP conditions_acceptees_version');
        $this->addSql('ALTER TABLE utilisateur DROP conditions_acceptees_le');
    }
}
