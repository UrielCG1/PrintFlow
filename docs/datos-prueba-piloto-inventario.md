# Datos de ensayo del inventario en `dev`

**Origen revisado:** `base de datos.xlsx`, proporcionado por el cliente el 13 de octubre de 2026. Estos ejemplos son **sintéticos** y no representan existencias, precios, medidas ni equivalencias aprobadas por el cliente. No cargarlos en producción ni mezclarlos con un conteo físico oficial.

## Qué aporta el archivo

El libro enumera cinco máquinas, cuatro reglas generales de costeo y 18 nombres de productos: cuatro lonas y catorce viniles. La hoja de proveedores no contiene registros y la de clientes solo tiene encabezados. Algunos productos muestran cifras en la columna `ANCHO`, pero el archivo no indica su unidad ni la longitud de cada rollo. Tampoco contiene cantidades disponibles, unidades de compra/consumo, costos, lotes, ubicaciones, mínimos, necesidades por orden ni reglas técnicas de sustitución.

Por ello, **no convertir** cifras como `61`, `80`, `135` o `160` a centímetros o pulgadas por inferencia. La variante real `LONA-FRONT-13OZ-DEFAULT` conserva `PZA` y la marca de migración legada hasta que el cliente confirme qué objeto físico representa y cómo se mide su consumo.

Los códigos `QA-` siguientes son exclusivos del ensayo. Cada variante usa `M2` para compra, inventario y consumo: factor 1:1 **solo dentro del ejemplo**. Cada unidad física que se reciba debe tener un QR distinto; su superficie inicial es una medición supuesta para probar el sistema, no una dimensión deducida del Excel.

## Caso 1 — recepción, reserva, consumo y sobrante

Material de prueba inspirado en «LONA 13 OZ BTE» del libro, sin usar el código real del cliente.

| Dato de ensayo | Valor |
| --- | --- |
| Material / variante | `QA-LONA-13OZ` / `QA-LONA-13OZ-M2` |
| Unidad física recibida | Una muestra medida de **10 m²**, QR propio, referencia `QA-ENT-001` |
| Mínimo de prueba | **2 m²** |
| Orden de ensayo | Requiere **6 m²** de esta variante |
| Consumo confirmado | **6 m²** del QR recibido |
| Sobrante separado | **1.5 m²** con QR hijo y procedencia del original |

Resultado esperado: al recibir hay 10 m² físicos y disponibles; al apartar 6 m² siguen existiendo 10 m² físicos y quedan 4 m² disponibles. Tras consumir 6 m², el físico queda en 4 m² y el apartado pendiente en cero. Al separar 1.5 m², el QR original conserva 2.5 m² y el QR hijo 1.5 m²; el total continúa en 4 m². Reimprimir cualquiera de las etiquetas no aumenta el saldo.

## Caso 2 — faltante y sustituto autorizado

Dos variantes **ficticias** inspiradas en la familia de viniles brillantes. Su compatibilidad es un supuesto del ensayo, no una recomendación técnica para los viniles reales del cliente.

| Dato de ensayo | Valor |
| --- | --- |
| Requisito original | `QA-VINIL-BRILLANTE-A-M2`, **6 m²** |
| Existencia del original | **0 m²** |
| Sustituto disponible | `QA-VINIL-BRILLANTE-B-M2`, una unidad QR de **8 m²** |
| Regla de ensayo | **1 m²** sustituto por **1 m²** original, activada solo para estas variantes `QA-` |
| Decisión | Administración autoriza usar el sustituto para esta orden |

Resultado esperado: la aceptación genera un faltante de 6 m² y un aviso administrativo. Antes de autorizar, el QR `B` no satisface el requisito `A`. Tras la sustitución, se apartan 6 m² de `B`, quedan 2 m² disponibles de `B` y su QR pasa a ser el esperado para la operación. Registrar quién autorizó y por qué; no configurar esta regla para los materiales reales por similitud de nombre.

## Caso 3 — espera de compra y recepción parcial

Material de prueba inspirado en «LONA 18 OZ», sin asignarle el ancho numérico del libro.

| Dato de ensayo | Valor |
| --- | --- |
| Material / variante | `QA-LONA-18OZ` / `QA-LONA-18OZ-M2` |
| Existencia inicial | **0 m²** |
| Orden de ensayo | Requiere **5 m²**; Administración la pone en espera de compra |
| Solicitud interna | **5 m²**, referencia de prueba `QA-COMPRA-001` |
| Primera recepción | **2 m²**, QR individual y referencia `QA-ENT-002` |
| Segunda recepción | **3 m²**, otro QR y referencia `QA-ENT-003` |

Resultado esperado: crear o seguir la compra no aumenta el físico. La primera recepción deja 2 m² físicos apartados y faltan 3 m²; la orden continúa en espera. La segunda deja 5 m² físicos apartados, faltante cero y permite revisar la salida de espera sin iniciar producción automáticamente. Cada recepción tiene un QR y movimiento propios.

## Preparación y cierre del ensayo

1. Actualizar primero el código del servidor `dev` y aplicar las migraciones hasta `Version20261011320000`. La salida compartida del 13 de octubre solo llegaba a `Version20261011230000`.
2. Completar el alta de variantes desde la interfaz antes de cargar los casos: hoy el formulario de Materiales crea el material general, pero no una `MaterialVariant`, y recepción solo admite variantes existentes. Crear las variantes `QA-` de manera aislada en `dev`, sin sobrescribir `LONA-FRONT-13OZ-DEFAULT`. Confirmar también que la necesidad de cada orden puede guardarse antes de registrar existencias. No insertar saldos directamente en SQL.
3. Usar cuentas de Administración e Impresión y conservar folios, códigos de unidad, resultado de lectura QR y capturas de saldos. No compartir tokens QR completos ni datos de clientes reales en el reporte.
4. Comparar los resultados con la [auditoría de solo lectura](consulta-inventario-piloto.sql) y con la [guía de salida operativa](operacion-inventario-piloto-y-salida.md). Conservar los datos `QA-` identificados como ensayo; no borrarlos mediante SQL ni trasladarlos a una base productiva.
