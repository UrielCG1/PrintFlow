# Guía de usuario: descuentos y costeo en OoxCorp

## Objetivo

Esta guía explica cómo configurar y utilizar los descuentos de las cotizaciones:

- descuento por clase de cliente (lealtad);
- descuento por volumen, según la línea de negocio;
- descuento adicional autorizado por un administrador.

Los descuentos se muestran por separado en la cotización, el PDF y los registros de auditoría.

## Requisitos y permisos

Estas acciones requieren una cuenta con permisos administrativos:

| Acción | Permiso |
|---|---|
| Consultar configuración | `discounts.view` |
| Editar clases y porcentajes | `discounts.client_classes.manage` |
| Editar reglas de volumen | `discounts.volume_rules.manage` |
| Asignar clase a clientes | `clients.assign_class` |
| Asignar override a contactos | `clients.contacts.assign_class` |
| Aplicar descuento adicional | `quotations.apply_additional_discount` |
| Aprobar/rechazar volumen | `quotations.approve_volume_discount` |

La pantalla principal de configuración se encuentra en:

```text
/admin/descuentos
```

## 1. Configurar el descuento por clase de cliente

La clase representa el descuento automático por lealtad. Existen tres clases protegidas:

- A
- B
- C

Los porcentajes iniciales son `0.0000%` porque deben ser definidos por OoxCorp. No se deben inventar porcentajes durante la configuración.

### Pasos

1. Entrar a **Administración → Descuentos y costeo**.
2. Ubicar la sección **Clases y descuento por lealtad**.
3. Seleccionar la clase A, B o C.
4. Capturar el nombre visible de la clase.
5. Capturar el porcentaje entre `0` y `100`.
6. Presionar **Guardar clase**.

El porcentaje se guarda con cuatro decimales. Por ejemplo, `4.5` se conserva como `4.5000%`.

### Ejemplo

| Clase | Porcentaje configurado |
|---|---:|
| A | 8.0000% |
| B | 4.0000% |
| C | 0.0000% |

Si una cotización tiene un subtotal bruto de `$10,000.00` y el cliente efectivo es clase B, el descuento por lealtad será:

```text
$10,000.00 × 4% = $400.00
```

Este descuento se calcula sobre el subtotal bruto original de toda la cotización.

## 2. Asignar la clase al cliente

Los clientes nuevos, clientes existentes migrados y prospectos públicos comienzan en clase C.

### Pasos

1. Entrar a **Clientes**.
2. Crear un cliente nuevo o abrir **Editar cliente**.
3. Seleccionar **Clase de cliente**: A, B o C.
4. Guardar el cliente.

Sólo un administrador puede cambiar la clase del cliente. Cambiar esta clase no modifica las clases override que ya tengan los contactos.

## 3. Asignar una clase diferente a un contacto

Un contacto hereda automáticamente la clase de su cliente cuando el campo queda vacío.

También es posible asignar una clase exclusiva para ese contacto.

### Pasos

1. Entrar al cliente.
2. Abrir **Contactos**.
3. Crear o editar el contacto.
4. En **Clase del contacto**, seleccionar:
   - **Heredar clase del cliente**, o
   - A, B o C como override.
5. Guardar.

### Ejemplo

```text
Cliente: clase C
Contacto principal: hereda C
Contacto de compras: override B
```

Una cotización que utiliza el contacto de compras aplicará el porcentaje de clase B. La clase del cliente seguirá siendo C.

## 4. Configurar descuentos por volumen

El descuento por volumen es independiente del descuento por clase. Se calcula por línea de negocio, no sobre toda la cotización.

La línea de negocio corresponde a la categoría comercial, por ejemplo:

- Impresión digital
- Offset
- Promocionales
- Serigrafía

**Estado actual:** el motor suma la cantidad facturable de las partidas de una categoría; todavía no aplica las cuatro fórmulas técnicas de la [matriz de cotización](matriz-cotizacion-costos.md). Los perfiles nuevos se crean pendientes/inactivos. No configures tramos de offset o serigrafía hasta confirmar la base del umbral y enlazar su cálculo al cotizador.

### Reglas de volumen

Cada regla contiene:

- línea de negocio;
- volumen mínimo;
- porcentaje de descuento;
- estado activa/inactiva.

El límite superior es implícito: una regla aplica hasta que se alcanza el siguiente volumen mínimo.

Los mínimos, porcentajes y la unidad del umbral son datos pendientes del cliente. No hay escalas predeterminadas.

### Pasos para editar una regla

1. Entrar a `/admin/descuentos`.
2. Ubicar **Reglas de volumen**.
3. Editar el volumen mínimo, porcentaje o estado.
4. Presionar **Guardar regla**.

El sistema rechaza porcentajes fuera de `0–100`, volúmenes menores o iguales a cero, y porcentajes que disminuyan al aumentar el volumen mínimo.

> Importante: un perfil pendiente no activa reglas. Las reglas de precio unitario y las de descuento son configuraciones diferentes.

## 5. ¿Cuándo debe aprobarse un descuento por volumen?

Toda cotización que tenga un descuento por volumen mayor que `0%` genera una revisión en estado **Pendiente**.

El administrador debe aprobarla antes de:

- emitir formalmente la cotización;
- enviarla como cotización formal por correo;
- aceptar la cotización desde administración;
- aceptar la cotización mediante el enlace público.

La solicitud pública sí puede enviar inmediatamente un acuse con estimación y PDF provisional. Ese documento debe indicar que los importes están sujetos a revisión y no incluye un enlace de aceptación.

### Pasos para aprobar

1. Abrir el detalle de la cotización.
2. Revisar:
   - línea de negocio;
   - volumen acumulado;
   - tramo seleccionado;
   - porcentaje;
   - subtotal bruto de la línea;
   - importe del descuento.
3. En **Revisión de descuento por volumen**, presionar **Aprobar**.
4. Agregar notas si es necesario.

También se puede presionar **Rechazar**, pero el motivo es obligatorio. Mientras esté rechazado o pendiente, la cotización no puede pasar al flujo formal.

Si cambia una partida, cantidad, precio, línea de negocio, regla o importe del volumen, la aprobación anterior queda desactualizada y se crea una nueva revisión pendiente.

## 6. Agregar un descuento adicional

El descuento adicional es independiente del descuento por volumen y del descuento por lealtad. Sólo un administrador puede aplicarlo y debe registrar un motivo.

### Pasos

1. Abrir una cotización editable, antes de emitirla.
2. Ir a **Acciones comerciales**.
3. En **Descuento adicional**, capturar el porcentaje.
4. Capturar el motivo.
5. Presionar **Aplicar descuento adicional**.
6. Confirmar que el desglose muestre el nuevo descuento.

El porcentaje debe estar entre `0%` y `100%`. El motivo no puede quedar vacío.

### Ejemplo aditivo

Para una cotización con subtotal bruto de `$10,000.00`:

| Tipo | Porcentaje | Base | Importe |
|---|---:|---:|---:|
| Volumen | 6% | $6,000.00 | $360.00 |
| Lealtad | 4% | $10,000.00 | $400.00 |
| Adicional | 2% | $10,000.00 | $200.00 |
| **Total** | — | — | **$960.00** |

Los descuentos son aditivos sobre sus bases originales. No se calcula el segundo descuento sobre el saldo ya reducido.

## 7. Verificar el resultado en una cotización

En el detalle, PDF y documentos generados se debe revisar:

- subtotal bruto;
- cada tipo de descuento;
- línea de negocio o alcance;
- porcentaje;
- base original;
- importe descontado;
- descuento total;
- IVA y total final;
- estado de aprobación del volumen.

No se debe interpretar un único “porcentaje total” cuando existen descuentos con bases diferentes. La referencia principal es el importe total descontado y el desglose individual.

## 8. Recomendaciones operativas

- Configurar primero las clases y sus porcentajes autorizados.
- Verificar que cada línea activa tenga un perfil de costeo válido.
- Ordenar y revisar los tramos de volumen antes de activar reglas.
- No cambiar reglas para alterar una cotización ya emitida; crear una nueva revisión cuando corresponda.
- Revisar siempre el motivo del descuento adicional.
- No aprobar una cotización si el volumen, precio o porcentaje mostrado no coincide con la regla vigente.
- Mantener los porcentajes comerciales documentados y autorizados por OoxCorp.
