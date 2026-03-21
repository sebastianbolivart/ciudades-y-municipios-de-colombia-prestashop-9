<!-- MySQL & MariaDB Compatibility Guide -->

# ps_colombia_address - Compatibilidad MySQL / MariaDB

## Resumen
El módulo `ps_colombia_address` está 100% optimizado para funcionar en ambos **MySQL** y **MariaDB** sin cambios de configuración.

---

## Versiones Soportadas

| DBMS | Versiones | Estado |
|------|-----------|--------|
| **MySQL** | 5.7+ | ✅ Soportado |
| **MariaDB** | 10.1+ | ✅ Soportado |
| **MySQL** | 8.0+ | ✅ Soportado |
| **MariaDB** | 10.2+, 10.3+, 10.4+, 10.5+, 10.6+ | ✅ Soportado |

---

## Características de Compatibilidad

### 1. **Tabla y Estructura SQL**

✅ Las definiciones de tabla en `sql/install.sql` usan sintaxis compatible:
- `CREATE TABLE IF NOT EXISTS` — Ambos
- `INT UNSIGNED`, `VARCHAR`, `DECIMAL` — Ambos  
- `ENGINE=InnoDB` — Ambos (motor de almacenamiento universal)
- `CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` — Ambos
- Índices (`PRIMARY KEY`, `INDEX`) — Ambos

### 2. **Consultas SQL**

✅ Método `tableExists()` usa **INFORMATION_SCHEMA**:
```sql
SELECT 1 FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'database_name' 
  AND TABLE_NAME = 'table_name'
```
- Compatible con MySQL 5.7+
- Compatible con MariaDB 10.1+
- Incluye fallback con `DESCRIBE` para versiones muy antiguas

✅ Funciones de utilidad:
- `pSQL()` — Escapa cadenas (PrestaShop, compatible con ambos)
- `bqSQL()` — Encierra identificadores en backticks (PrestaShop, compatible con ambos)
- `number_format()` — Maneja DECIMAL para coordenadas (PHP nativo, garantizado)

### 3. **Tipo de Datos Numéricas**

✅ Manejo de floats/decimales:
```php
// Para latitud/longitud (DECIMAL(10,8) y DECIMAL(11,8))
$value = 4.742376;
$sqlValue = number_format($value, 10, '.', '');  // "4.7423760000"
```
- Funciona idénticamente en MySQL y MariaDB
- Preserva precisión decimal sin truncar

### 4. **Transacciones**

✅ `START TRANSACTION; ... COMMIT;` en scripts SQL:
- Sintaxis estándar SQL (ISO)
- Ambos soportan `InnoDB` transaccional

### 5. **Operaciones de Base Datos (Db::insert, Db::update)**

✅ Usa métodos PrestaShop estándar:
```php
$db->insert($table, $data);   // AbstractDbCore (ambos)
$db->update($table, $data, $where);  // AbstractDbCore (ambos)
$db->getValue($sql);           // AbstractDbCore (ambos)
$db->execute($sql);            // AbstractDbCore (ambos)
```
- Capa de abstracción de PrestaShop garantiza compatibilidad
- Nunca accede directamente a funciones de MySQLi o funciones obsoletas

### 6. **Escapes y Sanitización**

✅ `pSQL()` de PrestaShop:
- Usa `mysqli_real_escape_string()` en MySQL
- Usa equivalente en MariaDB
- Transparente para desarrollador

✅ `bqSQL()` de PrestaShop:
- Encierra identificadores con backticks
- Neutral a DBMS

---

## Prueba de Compatibilidad

### En MySQL 8.0
```bash
mysql> SELECT VERSION();
8.0.35
```
- ✅ Módulo funciona sin cambios

### En MariaDB 10.6
```bash
MariaDB [(none)]> SELECT VERSION();
10.6.14-MariaDB-1~deb11u1
```
- ✅ Módulo funciona sin cambios

---

## Scripts SQL de Instalación/Limpieza

### `sql/install.sql`
- **Genérico**: Usa `PREFIX_` como placeholder
- **Compatible**: Ambos MySQL y MariaDB
- **Ejecución**: PrestaShop lo procesa antes de instalar

### `sql/uninstall.sql`  
- **Genérico**: Usa `PREFIX_` como placeholder
- **Compatible**: Ambos MySQL y MariaDB
- **Ejecución**: Automática al desinstalar

### `sql/cleanup_orphan_module_generic.sql`
- **Propósito**: Limpiar registros residuales si instalación falla
- **Genérico**: Usa `PREFIX_` (sustituir manualmente por prefijo real, p.ej. `7qmfe_`)
- **Compatible**: Ambos MySQL y MariaDB
- **Transaccional**: Usa `START TRANSACTION` / `COMMIT`

---

## Notas de Implementación

| Aspecto | Detalles |
|---------|----------|
| **Collation** | utf8mb4_unicode_ci (estándar para ambos) |
| **Motor DBMS** | InnoDB (recomendado y compatible) |
| **Caracteres especiales** | Soportados completamente (acentos, ñ) |
| **Tipos DECIMAL** | Coordenadas (lat/lon) más precisas que FLOAT |
| **Concurrencias** | ACID transaccional (InnoDB) = robusto |
| **Performances** | Índices optimizados para ambos |

---

## Recomendaciones

1. **Actualizar servidor** si está en:
   - MySQL < 5.7 → Actualizar a 5.7+ o cambiar a MariaDB 10.1+
   - MariaDB < 10.1 → Actualizar a 10.1+

2. **Verificar variables globales** en servidor (opcional):
   ```sql
   -- MySQL/MariaDB
   SHOW VARIABLES LIKE 'character_set%';
   SHOW VARIABLES LIKE 'collation%';
   -- Deben incluir utf8mb4 / utf8mb4_unicode_ci
   ```

3. **Backup antes de actualizar** DBMS (siempre prudente)

---

## Troubleshooting

| Error | Causa | Solución |
|-------|-------|----------|
| `INFORMATION_SCHEMA` no existe | Versión muy antigua (<5.7) | Actualizar DBMS |
| DECIMAL precision truncada | Tipo float en lugar de DECIMAL | Usar fixtures/datos correctos CSV |
| Collation mismatch | Encoding diferente | Verificar `utf8mb4_unicode_ci` |
| Caracteres corruptos | Charset incorrecto en tabla | Ejecutar `ALTER TABLE ... CONVERT TO CHARSET utf8mb4` |

---

## Resumen Técnico

✅ **Capa de abstracción**: Usa API PrestaShop (Db) — agnóstica a DBMS  
✅ **Consultas**: SQL estándar ISO (INFORMATION_SCHEMA)  
✅ **Tipos de datos**: Estándar SQL (INT, VARCHAR, DECIMAL)  
✅ **Transacciones**: InnoDB nativo (ambos soportan)  
✅ **Sanitización**: `pSQL()` y `bqSQL()` de PrestaShop (tested)  
✅ **Fallbacks**: Manejo de excepciones para casos legacy  

**Conclusión**: El módulo está diseñado para funcionar en cualquier instalación PrestaShop 9.x con MySQL 5.7+ o MariaDB 10.1+ sin modifications.
