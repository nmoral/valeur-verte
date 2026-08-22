<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widget : escalade anti-abus d\'une page publique (défi durci, fenêtre de silence) — #268';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_publique ADD challenge_renforce_jusqua TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE page_publique ADD alertee_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_publique DROP challenge_renforce_jusqua');
        $this->addSql('ALTER TABLE page_publique DROP alertee_le');
    }
}
