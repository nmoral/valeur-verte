<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déclarations du pro (situation de revenus, retouches de chiffrage) — #26';
    }

    public function up(Schema $schema): void
    {
        // Champ distinct de l'instantané figé, au même titre que « ajustement » (#30).
        $this->addSql('ALTER TABLE rapport ADD declarations JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rapport DROP declarations');
    }
}
