<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Désabonnement à la relance de fin d\'essai : marqueur compte.relance_essai_desabonnee_le — #108';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD relance_essai_desabonnee_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP relance_essai_desabonnee_le');
    }
}
