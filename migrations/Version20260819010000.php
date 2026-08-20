<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260819010000 extends AbstractMigration
{
 public function getDescription():string{return 'Unifica solicitudes públicas y cotizaciones; agrega catálogo e historial de estados y elimina quote_request.';}
 public function isTransactional():bool{return false;}
 public function up(Schema $schema):void
 {
  $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,'Solo MySQL o MariaDB.');
  $this->addSql("CREATE TABLE quotation_statuses (code VARCHAR(30) NOT NULL, name VARCHAR(100) NOT NULL, display_order INT NOT NULL, is_terminal TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY(code)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
  $this->addSql("INSERT INTO quotation_statuses(code,name,display_order,is_terminal,is_active) VALUES ('REQUEST','Solicitud',10,0,1),('IN_REVIEW','En revisión',20,0,1),('DRAFT','Borrador',30,0,1),('ISSUED','Emitida',40,0,1),('SENT','Enviada',50,0,1),('ACCEPTED','Aceptada',60,1,1),('ACCEPTED_WITH_CHANGES','Aceptada con cambios',70,1,1),('REJECTED','Rechazada',80,1,1),('EXPIRED','Vencida',90,1,1),('CANCELLED','Cancelada',100,1,1),('SUPERSEDED','Reemplazada',110,1,1)");
  $this->addSql("INSERT INTO client_categories(name,description,code,discount_percentage,display_order,is_active,created_at,updated_at) VALUES ('Prospecto sin compras','Cliente creado desde una solicitud pública que todavía no registra compras.','PROSPECT_NO_PURCHASE',0.00,5,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),code=VALUES(code),discount_percentage=VALUES(discount_percentage),display_order=VALUES(display_order),is_active=1,updated_at=UTC_TIMESTAMP()");
  $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status');
  $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_issue_data');
  $this->addSql("ALTER TABLE quotations MODIFY created_by_user_id INT DEFAULT NULL, ADD origin VARCHAR(20) NOT NULL DEFAULT 'INTERNAL', ADD request_reference VARCHAR(40) DEFAULT NULL, ADD requested_delivery_at DATE DEFAULT NULL, ADD contact_preference VARCHAR(20) DEFAULT NULL, ADD delivery_method VARCHAR(30) DEFAULT NULL, ADD requires_invoice TINYINT(1) NOT NULL DEFAULT 0, ADD request_contact_name VARCHAR(160) DEFAULT NULL, ADD request_email VARCHAR(180) DEFAULT NULL, ADD request_phone VARCHAR(30) DEFAULT NULL, ADD request_company_name VARCHAR(180) DEFAULT NULL, ADD UNIQUE INDEX uniq_quotations_request_reference(request_reference)");
  $this->addSql("ALTER TABLE quotations ADD CONSTRAINT chk_quotations_status CHECK(status IN ('REQUEST','IN_REVIEW','DRAFT','ISSUED','SENT','ACCEPTED','ACCEPTED_WITH_CHANGES','REJECTED','EXPIRED','CANCELLED','SUPERSEDED')), ADD CONSTRAINT chk_quotations_issue_data CHECK ((status IN ('REQUEST','IN_REVIEW','DRAFT') AND folio IS NULL AND issued_at IS NULL) OR (status NOT IN ('REQUEST','IN_REVIEW','DRAFT') AND folio IS NOT NULL AND issued_at IS NOT NULL)), ADD CONSTRAINT fk_quotations_status_catalog FOREIGN KEY(status) REFERENCES quotation_statuses(code) ON DELETE RESTRICT");
  $this->addSql('ALTER TABLE quotation_items ADD request_details JSON DEFAULT NULL, ADD attachment_path VARCHAR(255) DEFAULT NULL, ADD attachment_original_name VARCHAR(255) DEFAULT NULL');
  $this->addSql("CREATE TABLE quotation_status_history (id INT AUTO_INCREMENT NOT NULL, quotation_id INT NOT NULL, from_status VARCHAR(30) DEFAULT NULL, to_status VARCHAR(30) NOT NULL, changed_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_quotation_status_history_timeline(quotation_id,changed_at), INDEX idx_qsh_from_status(from_status), INDEX idx_qsh_to_status(to_status), PRIMARY KEY(id), CONSTRAINT fk_qsh_quotation FOREIGN KEY(quotation_id) REFERENCES quotations(id) ON DELETE CASCADE, CONSTRAINT fk_qsh_from_status FOREIGN KEY(from_status) REFERENCES quotation_statuses(code) ON DELETE RESTRICT, CONSTRAINT fk_qsh_to_status FOREIGN KEY(to_status) REFERENCES quotation_statuses(code) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
  $this->addSql("INSERT INTO quotation_status_history(quotation_id,from_status,to_status,changed_at) SELECT id,NULL,status,created_at FROM quotations");
  $this->addSql('DROP TABLE quote_request_items');
  $this->addSql('DROP TABLE quote_request');
 }
 public function down(Schema $schema):void{$this->abortIf(true,'Migración irreversible: las tablas de solicitudes legadas fueron eliminadas por decisión funcional. Restaura un respaldo para volver atrás.');}
}
