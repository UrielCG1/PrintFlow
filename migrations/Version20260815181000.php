<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Documenta físicamente el modelo en MySQL mediante TABLE_COMMENT y COLUMN_COMMENT. */
final class Version20260815181000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Agrega comentarios SQL en español a tablas y columnas del modelo de inventario, costos y producción.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        foreach ($this->tableComments() as $table => $comment) {
            $this->addSql(sprintf(
                'ALTER TABLE `%s` COMMENT = %s',
                $table,
                $this->connection->quote($comment),
            ));
        }

        foreach ($this->columnComments() as $table => $columns) {
            $changes = [];
            foreach ($columns as $column => [$definition, $comment]) {
                $changes[] = sprintf(
                    'MODIFY `%s` %s COMMENT %s',
                    $column,
                    $definition,
                    $this->connection->quote($comment),
                );
            }
            $this->addSql(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $changes)));
        }
    }

    public function down(Schema $schema): void
    {
        // Los comentarios no afectan datos ni comportamiento. Se retira el comentario
        // de tabla; conservar COLUMN_COMMENT durante rollback evita repetir todas las
        // definiciones físicas y no impide que la migración estructural sea revertida.
        foreach (array_keys($this->tableComments()) as $table) {
            $this->addSql(sprintf('ALTER TABLE `%s` COMMENT = \'\'', $table));
        }
    }

    /** @return array<string,string> */
    private function tableComments(): array
    {
        return [
            'brands' => $this->catalogColumns('Marca comercial asociable a una variante.'),
            'manufacturers' => $this->catalogColumns('Fabricante del material, independiente del proveedor.'),
            'colors' => array_merge($this->catalogColumns('Color físico del insumo.'), [
                'hex_value' => ['VARCHAR(7) DEFAULT NULL', 'Representación visual hexadecimal opcional; no es una especificación colorimétrica.'],
            ]),
            'finishes' => $this->catalogColumns('Acabado superficial de la variante.'),
            'adhesive_types' => $this->catalogColumns('Tecnología de adhesivo de la variante.'),
            'measurement_units' => 'Catálogo de unidades, dimensiones físicas y conversiones universales compatibles.',
            'material_categories' => 'Jerarquía funcional de categorías de materias primas e insumos.',
            'materials' => 'Conceptos técnicos generales; las presentaciones comprables se guardan en material_variants.',
            'brands' => 'Catálogo de marcas comerciales de presentaciones de materiales.',
            'manufacturers' => 'Catálogo de fabricantes, independiente de los proveedores comerciales.',
            'colors' => 'Catálogo del color físico del insumo; no describe los colores de archivos impresos.',
            'finishes' => 'Catálogo de acabados superficiales como mate, brillante o satinado.',
            'adhesive_types' => 'Catálogo de tecnologías de adhesivo.',
            'material_variants' => 'Presentaciones concretas que se compran, almacenan, consumen y costean.',
            'material_variant_conversions' => 'Conversiones dependientes de una presentación, como rollo a metro lineal.',
            'supplier_material_variants' => 'Ofertas comerciales de proveedores para presentaciones específicas.',
            'product_categories' => 'Jerarquía comercial de productos, separada de categorías de materiales.',
            'products' => 'Productos y servicios fabricados, revendidos o configurables.',
            'bill_of_material_items' => 'Renglones de receta BOM y reglas versionadas de consumo planeado.',
            'inventory_lots' => 'Lotes internos de recepción y trazabilidad de fabricante/caducidad.',
            'inventory_movements' => 'Libro inmutable de entradas, salidas, reservas y liberaciones de inventario.',
            'material_variant_costs' => 'Proyección actual del saldo y costo promedio móvil por variante.',
            'production_material_usages' => 'Comparación del consumo planeado, real opcional, contabilizado y merma.',
        ];
    }

    /** @return array<string,array<string,array{string,string}>> */
    private function columnComments(): array
    {
        return [
            'measurement_units' => [
                'base_unit_id' => ['INT DEFAULT NULL', 'Unidad base de la misma dimensión; NULL para presentaciones contextuales como rollo.'],
                'symbol' => ["VARCHAR(20) NOT NULL DEFAULT ''", 'Abreviatura visible de la unidad.'],
                'dimension_type' => ["VARCHAR(20) NOT NULL DEFAULT 'COUNT'", 'Dimensión física: COUNT, LENGTH, AREA, VOLUME, MASS o TIME.'],
                'conversion_factor' => ['NUMERIC(24,12) NOT NULL DEFAULT 1', 'Factor universal hacia la unidad base de la misma dimensión.'],
                'decimal_scale' => ['SMALLINT UNSIGNED NOT NULL DEFAULT 6', 'Decimales recomendados para presentar o redondear cantidades.'],
                'allows_fraction' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si la unidad acepta cantidades fraccionarias.'],
            ],
            'material_categories' => [
                'parent_id' => ['INT DEFAULT NULL', 'Categoría superior opcional; no puede formar ciclos.'],
                'category_type' => ["VARCHAR(24) NOT NULL DEFAULT 'CONSUMABLE'", 'Clasificación funcional del insumo.'],
                'inventory_controlled' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Valor predeterminado de control de inventario para la categoría.'],
            ],
            'materials' => [
                'default_inventory_unit_id' => ['INT DEFAULT NULL', 'Unidad de inventario sugerida para nuevas variantes.'],
                'default_consumption_unit_id' => ['INT DEFAULT NULL', 'Unidad de consumo sugerida para nuevas recetas.'],
                'material_type' => ["VARCHAR(30) NOT NULL DEFAULT 'CONSUMABLE'", 'Clasificación técnica general del material.'],
                'is_stock_item' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si sus variantes mantienen existencia física.'],
                'is_purchasable' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si puede abastecerse mediante compras.'],
                'is_consumable' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si puede incluirse en recetas de producción.'],
                'is_hazardous' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Señala condiciones especiales de seguridad o almacenamiento.'],
                'requires_lot_control' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Valor predeterminado de trazabilidad por lote.'],
                'requires_expiration_control' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Valor predeterminado de control de caducidad.'],
                'default_waste_percentage' => ['NUMERIC(7,4) NOT NULL DEFAULT 0', 'Desperdicio sugerido cuando la BOM no define uno específico.'],
                'storage_conditions' => ['LONGTEXT DEFAULT NULL', 'Condiciones generales de almacenamiento.'],
                'technical_notes' => ['LONGTEXT DEFAULT NULL', 'Notas técnicas que no sustituyen atributos estructurados.'],
            ],
            'material_variants' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno de la presentación.'],
                'material_id' => ['INT NOT NULL', 'Material general al que pertenece.'],
                'brand_id' => ['INT DEFAULT NULL', 'Marca comercial opcional.'],
                'color_id' => ['INT DEFAULT NULL', 'Color físico opcional del insumo.'],
                'finish_id' => ['INT DEFAULT NULL', 'Acabado superficial opcional.'],
                'adhesive_type_id' => ['INT DEFAULT NULL', 'Tecnología de adhesivo opcional.'],
                'purchase_unit_id' => ['INT NOT NULL', 'Unidad en la que se compra al proveedor.'],
                'inventory_unit_id' => ['INT NOT NULL', 'Unidad utilizada para expresar existencia.'],
                'consumption_unit_id' => ['INT NOT NULL', 'Unidad utilizada por recetas y producción.'],
                'code' => ['VARCHAR(80) NOT NULL', 'Código interno único de la presentación.'],
                'manufacturer_sku' => ['VARCHAR(100) DEFAULT NULL', 'SKU opcional asignado por el fabricante.'],
                'barcode' => ['VARCHAR(80) DEFAULT NULL', 'Código de barras opcional.'],
                'specifications' => ['JSON NOT NULL', 'Dimensiones y propiedades técnicas estructuradas con sus unidades.'],
                'purchase_to_inventory_factor' => ['NUMERIC(24,12) NOT NULL DEFAULT 1', 'Unidades de inventario contenidas en una unidad de compra.'],
                'inventory_to_consumption_factor' => ['NUMERIC(24,12) NOT NULL DEFAULT 1', 'Unidades de consumo contenidas en una unidad de inventario.'],
                'reference_cost_mxn' => ['NUMERIC(19,6) DEFAULT NULL', 'Costo orientativo en MXN; no reemplaza compras ni promedio móvil.'],
                'minimum_stock' => ['NUMERIC(20,6) NOT NULL DEFAULT 0', 'Nivel mínimo deseado en unidad de inventario.'],
                'reorder_point' => ['NUMERIC(20,6) NOT NULL DEFAULT 0', 'Saldo que dispara sugerencia de reabasto.'],
                'reorder_quantity' => ['NUMERIC(20,6) NOT NULL DEFAULT 0', 'Cantidad sugerida para reabastecer.'],
                'lot_controlled' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Obliga a indicar lote en movimientos físicos.'],
                'expiration_controlled' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Obliga a indicar caducidad en el lote.'],
                'is_default' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Variante propuesta cuando la receta solo exige el material.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Permite usar la variante en operaciones nuevas.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'material_variant_conversions' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno de la conversión.'],
                'material_variant_id' => ['INT NOT NULL', 'Presentación para la que aplica la equivalencia.'],
                'from_unit_id' => ['INT NOT NULL', 'Unidad de origen.'],
                'to_unit_id' => ['INT NOT NULL', 'Unidad de destino.'],
                'factor' => ['NUMERIC(24,12) NOT NULL', 'Multiplicador: destino = origen por factor.'],
                'is_bidirectional' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Permite resolver también la equivalencia inversa.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si la conversión continúa vigente.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'supplier_material_variants' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno de la oferta.'],
                'supplier_id' => ['INT NOT NULL', 'Proveedor que realiza la oferta.'],
                'material_variant_id' => ['INT NOT NULL', 'Presentación exacta ofrecida.'],
                'purchase_unit_id' => ['INT NOT NULL', 'Unidad comercial a la que corresponde el costo.'],
                'supplier_sku' => ['VARCHAR(100) DEFAULT NULL', 'Código utilizado por el proveedor.'],
                'unit_cost_mxn' => ['NUMERIC(19,6) NOT NULL', 'Costo unitario vigente expresado en MXN.'],
                'valid_from' => ['DATETIME NOT NULL', 'Inicio UTC de la vigencia.'],
                'valid_until' => ['DATETIME DEFAULT NULL', 'Fin UTC opcional de la vigencia.'],
                'is_preferred' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Indica si PRINTFLOW debe proponer primero esta oferta.'],
                'priority' => ['INT NOT NULL DEFAULT 100', 'Orden relativo entre proveedores alternativos.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Permite seleccionar la oferta en compras nuevas.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'product_categories' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno de la categoría.'],
                'parent_id' => ['INT DEFAULT NULL', 'Categoría comercial superior; no puede formar ciclos.'],
                'code' => ['VARCHAR(40) NOT NULL', 'Código comercial estable y único.'],
                'name' => ['VARCHAR(120) NOT NULL', 'Nombre visible de la categoría.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si acepta productos nuevos.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'products' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno del producto.'],
                'category_id' => ['INT NOT NULL', 'Categoría comercial del producto.'],
                'sale_unit_id' => ['INT NOT NULL', 'Unidad utilizada para vender y cotizar.'],
                'production_unit_id' => ['INT NOT NULL', 'Unidad base para planificar producción.'],
                'code' => ['VARCHAR(80) NOT NULL', 'Código estable del producto.'],
                'name' => ['VARCHAR(180) NOT NULL', 'Nombre comercial visible.'],
                'description' => ['LONGTEXT DEFAULT NULL', 'Descripción comercial opcional.'],
                'product_type' => ['VARCHAR(20) NOT NULL', 'Tipo: MANUFACTURED, RESALE, SERVICE o CONFIGURABLE.'],
                'base_price_mxn' => ['NUMERIC(19,4) DEFAULT NULL', 'Precio base opcional expresado en MXN.'],
                'configuration_schema' => ['JSON DEFAULT NULL', 'Opciones, restricciones y valores predeterminados configurables.'],
                'requires_production' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Indica si genera planificación de producción.'],
                'requires_installation' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Indica si requiere actividades de instalación.'],
                'is_stock_item' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Indica si el producto terminado mantiene existencia.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Permite usarlo en cotizaciones nuevas.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'bill_of_material_items' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno del renglón de receta.'],
                'product_id' => ['INT NOT NULL', 'Producto propietario de la receta.'],
                'material_id' => ['INT DEFAULT NULL', 'Material general cuando puede elegirse una variante compatible.'],
                'material_variant_id' => ['INT DEFAULT NULL', 'Variante exacta cuando no se permite sustitución.'],
                'measurement_unit_id' => ['INT NOT NULL', 'Unidad del consumo calculado.'],
                'quantity' => ['NUMERIC(20,6) NOT NULL', 'Coeficiente base utilizado por el cálculo.'],
                'waste_percentage' => ['NUMERIC(7,4) NOT NULL DEFAULT 0', 'Desperdicio planeado específico del renglón.'],
                'calculation_method' => ['VARCHAR(30) NOT NULL', 'Algoritmo predefinido de cálculo.'],
                'calculation_method_version' => ['INT NOT NULL DEFAULT 1', 'Versión que permite reproducir resultados históricos.'],
                'calculation_parameters' => ['JSON DEFAULT NULL', 'Parámetros validados; nunca contiene código ejecutable.'],
                'sequence' => ['INT NOT NULL', 'Posición del material en la receta.'],
                'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si participa en cálculos nuevos.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'inventory_lots' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno del lote.'],
                'material_variant_id' => ['INT NOT NULL', 'Presentación física recibida.'],
                'internal_lot_number' => ['VARCHAR(100) NOT NULL', 'Folio único generado por PRINTFLOW.'],
                'manufacturer_lot_number' => ['VARCHAR(100) DEFAULT NULL', 'Número opcional impreso por fabricante o proveedor.'],
                'manufactured_at' => ['DATE DEFAULT NULL', 'Fecha de fabricación declarada.'],
                'expires_at' => ['DATE DEFAULT NULL', 'Fecha de caducidad cuando la variante la controla.'],
                'received_unit_cost_mxn' => ['NUMERIC(19,6) DEFAULT NULL', 'Costo unitario original de recepción en MXN.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
            'inventory_movements' => [
                'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno del movimiento.'],
                'material_variant_id' => ['INT NOT NULL', 'Presentación cuyo saldo se afecta o reserva.'],
                'lot_id' => ['INT DEFAULT NULL', 'Lote afectado cuando existe control de trazabilidad.'],
                'unit_id' => ['INT NOT NULL', 'Unidad en que se expresa la cantidad.'],
                'responsible_user_id' => ['INT NOT NULL', 'Usuario responsable de confirmar el movimiento.'],
                'negative_stock_authorized_by' => ['INT DEFAULT NULL', 'Usuario que autorizó excepcionalmente existencia negativa.'],
                'movement_type' => ['VARCHAR(30) NOT NULL', 'Naturaleza de la entrada, salida, reserva o liberación.'],
                'quantity' => ['NUMERIC(20,6) NOT NULL', 'Cantidad firmada del movimiento.'],
                'unit_cost_mxn' => ['NUMERIC(19,6) DEFAULT NULL', 'Costo unitario histórico en MXN.'],
                'source_type' => ['VARCHAR(50) NOT NULL', 'Tipo de documento que originó el movimiento.'],
                'source_id' => ['INT DEFAULT NULL', 'Identificador opcional del documento origen.'],
                'is_provisional_receipt' => ['TINYINT(1) NOT NULL DEFAULT 0', 'Recepción física todavía pendiente de factura.'],
                'negative_stock_reason' => ['VARCHAR(255) DEFAULT NULL', 'Justificación obligatoria de la excepción de saldo negativo.'],
                'occurred_at' => ['DATETIME NOT NULL', 'Momento UTC del hecho físico.'],
                'created_at' => ['DATETIME NOT NULL', 'Momento UTC en que se registró.'],
            ],
            'material_variant_costs' => [
                'material_variant_id' => ['INT NOT NULL', 'Variante y clave primaria de la proyección.'],
                'on_hand_quantity' => ['NUMERIC(20,6) NOT NULL DEFAULT 0', 'Existencia física actual en unidad de inventario.'],
                'inventory_value_mxn' => ['NUMERIC(19,6) NOT NULL DEFAULT 0', 'Valor contable total actual en MXN.'],
                'moving_average_cost_mxn' => ['NUMERIC(19,6) NOT NULL DEFAULT 0', 'Costo promedio móvil por unidad de inventario.'],
                'updated_at' => ['DATETIME NOT NULL', 'Instante UTC de última actualización transaccional.'],
            ],
            'production_material_usages' => [
                'service_order_item_id' => ['INT NOT NULL', 'Partida de orden que consume el material.'],
                'material_variant_id' => ['INT NOT NULL', 'Presentación prevista o utilizada.'],
                'lot_id' => ['INT DEFAULT NULL', 'Lote físico consumido cuando aplica.'],
                'unit_id' => ['INT NOT NULL', 'Unidad común de las cantidades registradas.'],
                'inventory_movement_id' => ['INT DEFAULT NULL', 'Movimiento definitivo que descontó inventario.'],
                'measured_by' => ['INT DEFAULT NULL', 'Usuario que confirmó la medición real.'],
                'planned_quantity' => ['NUMERIC(20,6) NOT NULL', 'Consumo calculado originalmente por la BOM.'],
                'actual_quantity' => ['NUMERIC(20,6) DEFAULT NULL', 'Consumo real opcional; NULL si no fue capturado.'],
                'posted_quantity' => ['NUMERIC(20,6) NOT NULL', 'Cantidad usada para inventario y costos.'],
                'waste_quantity' => ['NUMERIC(20,6) DEFAULT NULL', 'Porción identificada como merma dentro del consumo real.'],
                'quantity_source' => ['VARCHAR(20) NOT NULL', 'Origen: ESTIMATED, MEASURED, DERIVED o MACHINE.'],
                'measurement_method' => ['VARCHAR(30) DEFAULT NULL', 'Técnica utilizada para obtener el consumo real.'],
                'waste_reason' => ['VARCHAR(255) DEFAULT NULL', 'Explicación opcional de la merma.'],
                'calculation_method' => ['VARCHAR(30) NOT NULL', 'Algoritmo predefinido utilizado para lo planeado.'],
                'calculation_method_version' => ['INT NOT NULL', 'Versión exacta del algoritmo.'],
                'calculation_snapshot' => ['JSON NOT NULL', 'Fotografía de entradas, parámetros y resultado original.'],
                'measured_at' => ['DATETIME DEFAULT NULL', 'Instante UTC de la medición real.'],
                'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
                'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
            ],
        ];
    }

    /** @return array<string,array{string,string}> */
    private function catalogColumns(string $subject): array
    {
        return [
            'id' => ['INT AUTO_INCREMENT NOT NULL', 'Identificador interno del registro.'],
            'code' => ['VARCHAR(40) NOT NULL', 'Código técnico estable y único.'],
            'name' => [str_contains($subject, 'Fabricante') ? 'VARCHAR(160) NOT NULL' : (str_contains($subject, 'Marca') ? 'VARCHAR(120) NOT NULL' : 'VARCHAR(100) NOT NULL'), $subject],
            'is_active' => ['TINYINT(1) NOT NULL DEFAULT 1', 'Indica si puede asignarse a registros nuevos.'],
            'created_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de creación.'],
            'updated_at' => ['DATETIME NOT NULL', 'Fecha y hora UTC de última actualización.'],
        ];
    }
}
