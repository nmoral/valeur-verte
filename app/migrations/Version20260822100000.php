<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widget : suspension d\'une page publique (kill switch admin / escalade anti-abus) — #268';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_publique ADD suspendue_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_publique DROP suspendue_le');
    }
}
