# Manual Técnico: API de Facturación CFDI 4.0 + Hidrocarburos (PHP 8.3)

Este documento detalla la arquitectura, el estándar de datos y el proceso de prueba para el sistema de facturación migrado de PHP 5.6 a PHP 8.3.

## 1. Arquitectura del Sistema
La API se divide en endpoints REST que actúan como puente hacia la librería **Electronic Document Library (EDL)**.

### Archivos Clave:
- `api/security.php`: Gestiona la autenticación mediante Bearer Tokens.
- `api/timbrar_cfdi40.php`: Motor estándar para facturación general.
- `api/timbrar_hidrocarburos.php`: Motor avanzado con soporte para el complemento de Hidrocarburos y Petrolíferos.

---

## 2. El "Payload Maestro" (Estándar JSON)
Se ha definido un formato JSON unificado que permite enviar tanto facturas simples como complejas.

### Estructura Raíz:
- `max_id`: (Entero) ID de transacción para control interno.
- `emisor`: RFC, Nombre y Régimen Fiscal del emisor.
- `receptor`: RFC, Nombre, Régimen, Uso de CFDI y Domicilio Fiscal (Mandatorio CFDI 4.0).
- `comprobante`: Datos generales (Serie, Folio, Moneda, Totales).
- `conceptos`: Array de productos/servicios.
- `impuestos`: Resumen global de traslados y retenciones.

---

## 3. Módulo de Hidrocarburos Petrolíferos
Para facturar productos regulados por la CRE, se debe insertar el objeto `complemento_hidrocarburos` dentro de cada concepto que lo requiera.

### Ejemplo de Bloque Hidrocarburos:
```json
"complemento_hidrocarburos": {
    "version": "1.0",
    "tipoPermiso": "PER11",  // Expendio al público en estación de servicio
    "numeroPermiso": "PL/6598/EXP/ES/2015",
    "claveHyP": "15101514", // Clave SAT para Gasolina
    "subProductoHyP": "SP16" // Clave SAT para Magna
}
```
> [!IMPORTANT]
> **Nunca** enviar nombres  descriptivosen `tipoPermiso` o `subProductoHyP`. Se deben usar exclusivamente las **claves alfanuméricas** del catálogo del SAT (ej. `PER11` en lugar de "Expendio").

---

## 4. Proceso de Pruebas y Validación
Para garantizar la estabilidad antes de producción, se utiliza un entorno de pruebas ficticio.

### Pasos para Probar:
1. **Servidor Local:** Ejecutar `php -S localhost:8080` en la carpeta raíz `/edl`.
2. **Postman:** Importar la colección `Pruebas_EDL.postman_collection.json`.
3. **Seguridad:** Todas las peticiones deben llevar la cabecera:
   `Authorization: Bearer Darkbyte234327*`

### Resultados Esperados:
- **Error PAC 4 - 1:** "IP no en lista blanca" → **ÉXITO**. El XML es válido y el PAC lo recibió.
- **Error PAC 3 - 997:** "Error de esquema" → **REVISAR DATOS**. Generalmente es por usar claves incorrectas en los catálogos.

---

## 5. Próximos Pasos para Producción
1. **Lista Blanca:** Registrar la IP pública del servidor de producción en el panel de Ecodex.
2. **Certificados Reales:** Colocar los archivos `.cer` y `.key` reales en la carpeta `/certificados`.
3. **Contraseñas:** Actualizar el archivo `configpasswords/.ini` con las cleavs de los CSD reales.
4. **ID Integrador:** Cambiar el `ID_INTEGRADOR` en los archivos de la carpeta `api/` por el ID de producción asignado.

---
**Desarrollado para:** EDL PHP 8.3 Migration Project
**Fecha:** Mayo 2026
