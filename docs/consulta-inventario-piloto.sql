-- Auditoría de solo lectura para el piloto de inventario (MariaDB/MySQL).
-- Requiere que el checkout y la base hayan llegado a Version20261011320000.
-- Ejecutar sobre la base confirmada en hPanel. No modifica saldos ni órdenes.
-- Los saldos de esta consulta son del sistema; el conteo físico se registra aparte.

-- 1. Identificar la base consultada y su versión.
SELECT DATABASE() AS base_consultada, VERSION() AS motor;

-- 2. Comparar la proyección con las unidades QR y apartados pendientes.
-- Cualquier diferencia distinta de cero requiere investigación antes del corte.
SELECT
    variant.code AS variante,
    unit.code AS unidad,
    COALESCE(balance.on_hand_quantity, 0) AS fisico_sistema,
    COALESCE(stock.physical_quantity, 0) AS suma_qr,
    COALESCE(balance.on_hand_quantity, 0) - COALESCE(stock.physical_quantity, 0) AS diferencia_fisico_qr,
    COALESCE(balance.blocked_quantity, 0) AS bloqueado_sistema,
    COALESCE(stock.blocked_quantity, 0) AS bloqueado_qr,
    COALESCE(balance.committed_quantity, 0) AS apartado_sistema,
    COALESCE(commitments.pending_quantity, 0) AS apartados_pendientes,
    COALESCE(balance.committed_quantity, 0) - COALESCE(commitments.pending_quantity, 0) AS diferencia_apartados,
    COALESCE(balance.on_hand_quantity, 0)
        - COALESCE(balance.blocked_quantity, 0)
        - COALESCE(balance.committed_quantity, 0) AS disponible
FROM material_variants variant
JOIN materials material ON material.id = variant.material_id
JOIN measurement_units unit ON unit.id = variant.inventory_unit_id
LEFT JOIN material_variant_costs balance ON balance.material_variant_id = variant.id
LEFT JOIN (
    SELECT material_variant_id,
           SUM(remaining_quantity) AS physical_quantity,
           SUM(CASE WHEN status = 'BLOCKED' THEN remaining_quantity ELSE 0 END) AS blocked_quantity
    FROM inventory_stock_units
    GROUP BY material_variant_id
) stock ON stock.material_variant_id = variant.id
LEFT JOIN (
    SELECT material_variant_id,
           SUM(committed_quantity - consumed_quantity - released_quantity) AS pending_quantity
    FROM inventory_commitments
    GROUP BY material_variant_id
) commitments ON commitments.material_variant_id = variant.id
WHERE material.is_active = 1
  AND material.is_stock_item = 1
  AND variant.is_active = 1
ORDER BY variant.code;

-- 3. Unidades cuya condición y saldo no concuerdan.
SELECT unit_code, status, remaining_quantity, material_variant_id
FROM inventory_stock_units
WHERE (status IN ('AVAILABLE', 'BLOCKED') AND remaining_quantity <= 0)
   OR (status IN ('EXHAUSTED', 'DISPOSED') AND remaining_quantity <> 0)
ORDER BY unit_code;

-- 4. Códigos o tokens QR duplicados. Las restricciones únicas deberían impedirlos.
SELECT unit_code, COUNT(*) AS veces
FROM inventory_stock_units
GROUP BY unit_code
HAVING COUNT(*) > 1;

SELECT COUNT(*) AS tokens_qr_duplicados
FROM (
    SELECT qr_token
    FROM inventory_stock_units
    GROUP BY qr_token
    HAVING COUNT(*) > 1
) duplicated_tokens;

-- 5. Apartados pendientes de órdenes canceladas que deberían haberse liberado.
SELECT `order`.folio, variant.code AS variante,
       commitment.committed_quantity - commitment.consumed_quantity - commitment.released_quantity AS pendiente
FROM inventory_commitments commitment
JOIN service_orders `order` ON `order`.id = commitment.service_order_id
JOIN material_variants variant ON variant.id = commitment.material_variant_id
WHERE `order`.production_status = 'CANCELLED'
  AND commitment.committed_quantity - commitment.consumed_quantity - commitment.released_quantity <> 0
ORDER BY `order`.folio, variant.code;

-- 6. Permisos efectivos por rol para los dos perfiles principales del piloto.
SELECT role.code AS rol, permission.code AS permiso
FROM roles role
JOIN role_permissions assignment ON assignment.role_id = role.id
JOIN permissions permission ON permission.id = assignment.permission_id
WHERE role.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
  AND (permission.code LIKE 'inventory.%' OR permission.code = 'service_orders.cancel')
ORDER BY role.code, permission.code;
