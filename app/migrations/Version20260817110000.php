<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suspension et prolongation d\'essai des comptes : compte.suspendu_le / essai_prolonge_jusquau — #47';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD suspendu_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE compte ADD essai_prolonge_jusquau TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP suspendu_le');
        $this->addSql('ALTER TABLE compte DROP essai_prolonge_jusquau');
    }
}
