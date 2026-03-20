# ps_colombia_address (PrestaShop 9)

Módulo de producción para PrestaShop 9 que implementa la jerarquía de direcciones de Colombia:

**País → Departamento → Municipio → Código Postal**

Incluye un dataset nacional con **1122 municipios**, código DANE y coordenadas geográficas, optimizado para checkout y logística.

---

## 1) Qué hace este módulo

- Reemplaza el flujo tradicional de ciudad libre por selección de municipio basada en departamento.
- Carga municipios dinámicamente por AJAX en formularios de dirección.
- Completa automáticamente código postal (opcional).
- Guarda metadatos de dirección (código DANE y coordenadas) para uso logístico.
- Provee panel administrativo (Symfony) para configuración e importación de CSV.
- No modifica archivos core de PrestaShop (solo hooks, controladores y servicios del módulo).

---

## 2) Requisitos

- **PrestaShop:** 9.x
- **PHP:** 8.1 o superior
- **Módulo:** `ps_colombia_address`
- País Colombia debe estar habilitado en la tienda para pruebas reales de checkout.

---

## 3) Estructura principal

Ruta del módulo: `ps_colombia_address/`

Componentes clave:

- `ps_colombia_address.php`: ciclo de vida del módulo y hooks.
- `data/municipios_colombia.csv`: dataset oficial cargado por instalación/importación.
- `controllers/front/municipalities.php`: endpoint AJAX de municipios.
- `views/js/checkout.js`: lógica dinámica en formulario de dirección/checkout.
- `src/Controller/Admin/ColombiaAddressController.php`: configuración en Back Office.
- `src/Form/ColombiaAddressFormModifier.php`: modificación del formulario de dirección.
- `sql/install.sql` y `sql/uninstall.sql`: creación/eliminación de tablas del módulo.

---

## 4) Instalación

### Opción A: ZIP (recomendada)

1. Comprime la carpeta `ps_colombia_address`.
2. En Back Office, ve a **Módulos > Gestor de módulos > Subir un módulo**.
3. Sube el ZIP.
4. Instala **Colombia Address Manager**.

### Opción B: Manual

1. Copia `ps_colombia_address/` en la carpeta `/modules` de PrestaShop.
2. Ve a **Módulos > Gestor de módulos**.
3. Busca `ps_colombia_address` e instala.

Durante la instalación, el módulo:

- Crea tablas propias.
- Importa automáticamente el CSV de municipios.
- Registra hooks de formulario y checkout.
- Crea configuración por defecto.

---

## 5) Configuración en Back Office

Después de instalar:

1. Abre el módulo en **Configurar**.
2. Ajusta opciones principales:
	 - Habilitar/deshabilitar módulo.
	 - Habilitar dropdown de municipio.
	 - Autocompletar código postal.
	 - Autocomplete (si está activo en frontend).
	 - Modo logística (si aplica a tu operación).
3. Guarda cambios.

### Gestión de dataset

Desde la vista de municipios puedes:

- Filtrar por departamento.
- Revisar municipios cargados.
- Importar un CSV actualizado.

Formato CSV esperado:

`department,municipality,postal_code,dane_code,latitude,longitude`

---

## 6) Uso en checkout y direcciones

Flujo esperado para cliente:

1. Selecciona **Colombia** como país.
2. Selecciona **Departamento**.
3. El módulo consulta municipios vía AJAX y llena el selector de **Municipio**.
4. Al elegir municipio:
	 - Sincroniza el valor de ciudad.
	 - Completa código postal (si está habilitado).
	 - Asocia código DANE y coordenadas para persistencia interna.

Esto aplica tanto en alta como en edición de direcciones, según hooks activos.

---

## 7) Tablas de base de datos

El módulo crea y usa solo tablas propias:

- `PREFIX_colombia_municipality`
	- Dataset de municipios (departamento, municipio, código postal, DANE, lat/lon).
- `PREFIX_colombia_address_extra`
	- Metadatos adicionales por dirección (`id_address`, DANE, coordenadas).

No altera la estructura de tablas nativas de PrestaShop.

---

## 8) Seguridad y buenas prácticas

- Validación de token en endpoint AJAX.
- Sanitización de entradas y consultas con utilidades de PrestaShop (`pSQL`, `bqSQL`).
- Escapado de salidas en plantillas Twig.
- Sin modificaciones a core.
- Archivos `index.php` en directorios internos para evitar listado.

---

## 9) Verificación rápida post-instalación

Checklist sugerido:

- El módulo aparece como instalado y activo.
- Se creó el menú/pantalla de configuración.
- En checkout, al cambiar departamento, se actualiza municipio.
- Código postal se rellena correctamente (si opción activa).
- Se pueden crear y editar direcciones sin errores.

---

## 10) Troubleshooting

### No aparece el selector de municipio

- Verifica que el módulo esté activo.
- Limpia caché de PrestaShop.
- Revisa que el JS del módulo cargue en checkout (`views/js/checkout.js`).
- Confirma que el país seleccionado sea Colombia.

### Error en Admin por módulo huérfano (carpeta faltante)

Si Back Office falla con un error similar a `RecursiveDirectoryIterator::__construct(.../modules/ps_colombia_address): Failed to open directory`, significa que el módulo quedó registrado en BD pero no existe su carpeta física.

Recuperación:

1. Restaura la carpeta `modules/ps_colombia_address` en el servidor (aunque sea mínima).
2. Ejecuta limpieza forzada con el script SQL:
	- `ps_colombia_address/sql/cleanup_orphan_module_ps_colombia_address.sql`
3. Limpia caché (`var/cache/prod` y `var/cache/dev`).
4. Vuelve a subir e instalar el módulo normalmente.

### No cargan municipios por AJAX

- Revisa que la ruta del controlador front esté accesible.
- Verifica token y consola del navegador.
- Comprueba que la tabla `PREFIX_colombia_municipality` tenga datos.

### Códigos postales vacíos

- Revisa si la opción de autocompletar está habilitada.
- Valida que el CSV importado incluya `postal_code`.

---

## 11) Desinstalación

Al desinstalar, el módulo:

- Elimina sus tablas propias.
- Limpia su configuración.
- Quita su pestaña administrativa.

No elimina ni modifica datos core fuera de su alcance.

---

## 12) Datos incluidos

- Dataset actual incluido: **1122 municipios** de Colombia.
- Fuente estructurada para operación ecommerce (logística, validación territorial, analítica por DANE).

---

## 13) Licencia y mantenimiento

Revisa `ps_colombia_address.php` y `config.xml` para metadatos de versión/autoría.
Si actualizas el CSV, documenta fecha y fuente para trazabilidad operativa.
