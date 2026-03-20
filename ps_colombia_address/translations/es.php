<?php
/**
 * Spanish (Colombia) translation catalogue for ps_colombia_address.
 *
 * Used by PrestaShop's legacy trans() mechanism when the Symfony Translator
 * is not yet initialised (e.g., during install()).
 *
 * Translations are keyed by the default English string.
 */

declare(strict_types=1);

global $_MODULE;

$_MODULE = [];

// ── Admin strings ─────────────────────────────────────────────────────────

$_MODULE['<{ps_colombia_address}prestashop>ps_colombia_address_']                              = 'ps_colombia_address';
$_MODULE['<{ps_colombia_address}prestashop>Colombia Address Manager']                          = 'Gestor de Dirección Colombia';
$_MODULE['<{ps_colombia_address}prestashop>Full Colombian geographic hierarchy: departments, municipalities, DANE codes and coordinates.']
                                                                                                = 'Jerarquía geográfica colombiana completa: departamentos, municipios, códigos DANE y coordenadas.';
$_MODULE['<{ps_colombia_address}prestashop>Configuration saved successfully.']                  = 'Configuración guardada correctamente.';
$_MODULE['<{ps_colombia_address}prestashop>Invalid security token.']                            = 'Token de seguridad inválido.';
$_MODULE['<{ps_colombia_address}prestashop>No valid file uploaded.']                            = 'No se subió ningún archivo válido.';
$_MODULE['<{ps_colombia_address}prestashop>File exceeds maximum allowed size (10 MB).']         = 'El archivo supera el tamaño máximo permitido (10 MB).';
$_MODULE['<{ps_colombia_address}prestashop>Only CSV files are allowed.']                        = 'Solo se permiten archivos CSV.';
$_MODULE['<{ps_colombia_address}prestashop>Could not move uploaded file.']                      = 'No se pudo mover el archivo subido.';
$_MODULE['<{ps_colombia_address}prestashop>Import failed: invalid CSV structure.']              = 'Importación fallida: estructura CSV inválida.';
$_MODULE['<{ps_colombia_address}prestashop>Dataset imported successfully (%d municipalities).'] = 'Dataset importado correctamente (%d municipios).';
$_MODULE['<{ps_colombia_address}prestashop>Configuration']                                      = 'Configuración';
$_MODULE['<{ps_colombia_address}prestashop>Dataset Status']                                     = 'Estado del Dataset';
$_MODULE['<{ps_colombia_address}prestashop>Municipalities loaded:']                             = 'Municipios cargados:';
$_MODULE['<{ps_colombia_address}prestashop>Manage dataset →']                                   = 'Gestionar dataset →';
$_MODULE['<{ps_colombia_address}prestashop>Module Settings']                                    = 'Ajustes del módulo';
$_MODULE['<{ps_colombia_address}prestashop>Enable module']                                      = 'Habilitar módulo';
$_MODULE['<{ps_colombia_address}prestashop>Enable municipality dropdown']                       = 'Habilitar desplegable de municipios';
$_MODULE['<{ps_colombia_address}prestashop>Autofill postal code']                               = 'Autocompletar código postal';
$_MODULE['<{ps_colombia_address}prestashop>Automatically fills the postal code field when a municipality is selected.']
                                                                                                = 'Rellena automáticamente el campo de código postal al seleccionar un municipio.';
$_MODULE['<{ps_colombia_address}prestashop>Enable municipality autocomplete']                   = 'Habilitar autocompletar de municipios';
$_MODULE['<{ps_colombia_address}prestashop>Logistics mode (expose DANE codes to shipping modules)']
                                                                                                = 'Modo logístico (exponer códigos DANE a módulos de envío)';
$_MODULE['<{ps_colombia_address}prestashop>Stores the DANE code alongside the address. Useful for shipping-carrier API integrations.']
                                                                                                = 'Almacena el código DANE junto a la dirección. Útil para integraciones con APIs de transportadoras.';
$_MODULE['<{ps_colombia_address}prestashop>Save configuration']                                 = 'Guardar configuración';

// ── Municipality manager ───────────────────────────────────────────────────

$_MODULE['<{ps_colombia_address}prestashop>Municipality Dataset']                               = 'Dataset de Municipios';
$_MODULE['<{ps_colombia_address}prestashop>Import CSV Dataset']                                 = 'Importar Dataset CSV';
$_MODULE['<{ps_colombia_address}prestashop>Upload a CSV file with columns: department, municipality, postal_code, dane_code, latitude, longitude.']
                                                                                                = 'Sube un archivo CSV con columnas: departamento, municipio, código_postal, código_DANE, latitud, longitud.';
$_MODULE['<{ps_colombia_address}prestashop>Current records:']                                   = 'Registros actuales:';
$_MODULE['<{ps_colombia_address}prestashop>CSV file (max 10 MB)']                               = 'Archivo CSV (máx. 10 MB)';
$_MODULE['<{ps_colombia_address}prestashop>Import & Replace Dataset']                           = 'Importar y reemplazar dataset';
$_MODULE['<{ps_colombia_address}prestashop>Browse by Department']                               = 'Explorar por Departamento';
$_MODULE['<{ps_colombia_address}prestashop>Department']                                         = 'Departamento';
$_MODULE['<{ps_colombia_address}prestashop>Select a department']                                = 'Seleccione un departamento';
$_MODULE['<{ps_colombia_address}prestashop>Municipality']                                       = 'Municipio';
$_MODULE['<{ps_colombia_address}prestashop>Postal Code']                                        = 'Código Postal';
$_MODULE['<{ps_colombia_address}prestashop>DANE Code']                                          = 'Código DANE';
$_MODULE['<{ps_colombia_address}prestashop>Latitude']                                           = 'Latitud';
$_MODULE['<{ps_colombia_address}prestashop>Longitude']                                          = 'Longitud';
$_MODULE['<{ps_colombia_address}prestashop>Municipalities in']                                  = 'Municipios en';
$_MODULE['<{ps_colombia_address}prestashop>No municipalities found for this department.']       = 'No se encontraron municipios para este departamento.';
$_MODULE['<{ps_colombia_address}prestashop>Back to configuration']                              = '← Volver a la configuración';
