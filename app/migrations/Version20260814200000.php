<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vérification d\'email a posteriori : utilisateur.email_verifie_le — #105 (ONB-1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur ADD email_verifie_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE utilisateur DROP email_verifie_le');
    }
}
