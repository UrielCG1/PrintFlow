-- PrintFlow: diagnóstico de materiales y unidades para el módulo de inventario.
-- Ejecutar en la base que el usuario ya tiene abierta en PuTTY/MySQL.
-- Todas las instrucciones son SELECT; no cambian datos ni estructura.
-- No se solicitan ni se incluyen contraseñas.
-- Revisar primero las consultas 1 y 2. Si el esquema difiere, adaptar las
-- consultas restantes a esas columnas antes de ejecutarlas.

-- 1. Identificar la base consultada y la versión real del motor.
SELECT DATABASE() AS base_consultada, VERSION() AS version_motor;

-- 2. Comprobar las columnas disponibles, sin asumir que coincide con el código local.
SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna, DATA_TYPE AS tipo
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'materials', 'material_variants', 'measurement_units',
    'material_variant_conversions', 'inventory_lots', 'inventory_movements'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Alternativas de solo lectura si la consulta 2 no devuelve filas.
-- Ejecutar individualmente, sin cambiar de base entre comandos.
-- Se dejan comentadas para conservar la numeración del diagnóstico original.
-- SHOW FULL COLUMNS FROM materials;
-- SHOW FULL COLUMNS FROM material_variants;
-- SHOW FULL COLUMNS FROM measurement_units;
-- SHOW FULL COLUMNS FROM inventory_lots;
-- SHOW FULL COLUMNS FROM inventory_movements;
-- SHOW TABLE STATUS WHERE Name IN ('inventory_lots', 'inventory_movements');
-- Si se compartió dos veces la salida de variantes, repetir la consulta 3
-- y conservar sus encabezados para distinguir los datos del material general.

-- 3. Catálogo existente de materiales y su unidad legada/predeterminada.
SELECT
  m.id AS material_id,
  m.code AS material_codigo,
  m.name AS material_nombre,
  m.is_active AS material_activo,
  m.is_stock_item AS controla_existencias,
  m.is_consumable AS consumible,
  u.code AS unidad_actual_codigo,
  u.name AS unidad_actual_nombre,
  u.symbol AS unidad_actual_simbolo,
  iu.code AS unidad_inventario_predeterminada,
  cu.code AS unidad_consumo_predeterminada
FROM materials m
LEFT JOIN measurement_units u ON u.id = m.measurement_unit_id
LEFT JOIN measurement_units iu ON iu.id = m.default_inventory_unit_id
LEFT JOIN measurement_units cu ON cu.id = m.default_consumption_unit_id
ORDER BY m.name, m.id;

-- 4. Presentaciones reales: unidades de compra, inventario y consumo por variante.
SELECT
  m.code AS material_codigo,
  m.name AS material_nombre,
  v.id AS variante_id,
  v.code AS variante_codigo,
  v.is_active AS variante_activa,
  pu.code AS unidad_compra,
  iu.code AS unidad_inventario,
  cu.code AS unidad_consumo,
  v.purchase_to_inventory_factor AS factor_compra_a_inventario,
  v.inventory_to_consumption_factor AS factor_inventario_a_consumo,
  v.minimum_stock AS minimo_variante,
  v.lot_controlled AS controla_lote,
  v.expiration_controlled AS controla_caducidad,
  v.specifications AS especificaciones
FROM material_variants v
JOIN materials m ON m.id = v.material_id
LEFT JOIN measurement_units pu ON pu.id = v.purchase_unit_id
LEFT JOIN measurement_units iu ON iu.id = v.inventory_unit_id
LEFT JOIN measurement_units cu ON cu.id = v.consumption_unit_id
ORDER BY m.name, v.code, v.id;

-- 5. Reglas del catálogo de unidades: dimensión, fracciones y conversión base.
SELECT
  u.id,
  u.code AS unidad_codigo,
  u.name AS unidad_nombre,
  u.symbol AS simbolo,
  u.dimension_type AS dimension,
  b.code AS unidad_base,
  u.conversion_factor AS factor_a_base,
  u.decimal_scale AS decimales,
  u.allows_fraction AS permite_fracciones,
  u.is_active AS activa
FROM measurement_units u
LEFT JOIN measurement_units b ON b.id = u.base_unit_id
ORDER BY u.display_order, u.name, u.id;

-- 6. Revisar si ya hay hechos de inventario antes de decidir el saldo inicial.
-- Estos conteos no certifican cantidades físicas ni deben interpretarse como stock.
SELECT 'inventory_lots' AS tabla, COUNT(*) AS registros FROM inventory_lots
UNION ALL
SELECT 'inventory_movements' AS tabla, COUNT(*) AS registros FROM inventory_movements;
