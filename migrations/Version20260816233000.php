<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816233000 extends AbstractMigration
{
    public function getDescription(): string { return 'Agrega aceptación pública segura, evidencia y estado aceptada con cambios.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Solo MySQL o MariaDB.');
        $this->addSql('ALTER TABLE quotations ADD acceptance_token VARCHAR(64) DEFAULT NULL, ADD acceptance_ip VARCHAR(45) DEFAULT NULL, ADD accepted_folio_snapshot VARCHAR(40) DEFAULT NULL, ADD accepted_amount_snapshot NUMERIC(14, 2) DEFAULT NULL, ADD acceptance_reviewed_by_user_id INT DEFAULT NULL, ADD acceptance_reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD INDEX IDX_QUOTATION_ACCEPTANCE_REVIEWER (acceptance_reviewed_by_user_id)');
        $this->addSql('ALTER TABLE quotations ADD CONSTRAINT FK_QUOTATION_ACCEPTANCE_REVIEWER FOREIGN KEY (acceptance_reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_QUOTATIONS_ACCEPTANCE_TOKEN ON quotations (acceptance_token)');
        $this->addSql("ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status");
        $this->addSql("ALTER TABLE quotations ADD CONSTRAINT chk_quotations_status CHECK (status IN ('DRAFT','ISSUED','SENT','ACCEPTED','ACCEPTED_WITH_CHANGES','REJECTED','EXPIRED','CANCELLED','SUPERSEDED'))");
        $this->addSql('ALTER TABLE quote_request ADD accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD accepted_by_name VARCHAR(160) DEFAULT NULL, ADD acceptance_notes LONGTEXT DEFAULT NULL, ADD acceptance_ip VARCHAR(45) DEFAULT NULL, ADD accepted_folio_snapshot VARCHAR(30) DEFAULT NULL, ADD accepted_amount_snapshot NUMERIC(14, 2) DEFAULT NULL, ADD acceptance_reviewed_by_user_id INT DEFAULT NULL, ADD acceptance_reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD INDEX IDX_QUOTE_REQUEST_ACCEPTANCE_REVIEWER (acceptance_reviewed_by_user_id)');
        $this->addSql('ALTER TABLE quote_request ADD CONSTRAINT FK_QUOTE_REQUEST_ACCEPTANCE_REVIEWER FOREIGN KEY (acceptance_reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE quotations SET status = 'SENT' WHERE status = 'ACCEPTED_WITH_CHANGES'");
        $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status');
        $this->addSql("ALTER TABLE quotations ADD CONSTRAINT chk_quotations_status CHECK (status IN ('DRAFT','ISSUED','SENT','ACCEPTED','REJECTED','EXPIRED','CANCELLED','SUPERSEDED'))");
        $this->addSql('ALTER TABLE quotations DROP FOREIGN KEY FK_QUOTATION_ACCEPTANCE_REVIEWER, DROP INDEX UNIQ_QUOTATIONS_ACCEPTANCE_TOKEN, DROP acceptance_token, DROP acceptance_ip, DROP accepted_folio_snapshot, DROP accepted_amount_snapshot, DROP acceptance_reviewed_by_user_id, DROP acceptance_reviewed_at');
        $this->addSql('ALTER TABLE quote_request DROP FOREIGN KEY FK_QUOTE_REQUEST_ACCEPTANCE_REVIEWER, DROP accepted_at, DROP accepted_by_name, DROP acceptance_notes, DROP acceptance_ip, DROP accepted_folio_snapshot, DROP accepted_amount_snapshot, DROP acceptance_reviewed_by_user_id, DROP acceptance_reviewed_at');
    }
}
