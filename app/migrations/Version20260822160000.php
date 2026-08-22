<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rétention : date d\'anonymisation d\'un rapport (D10) — #294';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rapport ADD anonymise_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rapport DROP anonymise_le');
    }
}
