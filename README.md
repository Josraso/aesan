# AESAN Checker

Aplicación para validar el etiquetado alimentario de productos cárnicos
vendidos online, conforme al **Plan Coordinado AESAN 2026**.

---

## Requisitos del servidor

- PHP 7.4 o superior (recomendado PHP 8.1+)
- MySQL 5.7+ o MariaDB 10.3+
- Extensiones PHP: `pdo`, `pdo_mysql`, `mbstring`, `zip`
- **Composer** (para instalar PhpSpreadsheet)

---

## Instalación

### 1. Subir archivos al servidor

Sube la carpeta `aesan/` a tu servidor (dentro de `public_html` o en una subcarpeta).

```
/public_html/aesan/
```

### 2. Instalar dependencias PHP

Desde SSH en la carpeta raíz de la app:

```bash
cd /public_html/aesan
composer require phpoffice/phpspreadsheet
```

Si no tienes Composer en el servidor, puedes correrlo en local y subir la carpeta `vendor/`.

### 3. Permisos de carpetas

```bash
chmod 755 uploads/
chmod 755 exports/
```

### 4. Ejecutar el instalador

Abre en el navegador:

```
https://tudominio.com/aesan/install/
```

El wizard de instalación te pedirá:
- **Paso 1**: Verificación automática de requisitos
- **Paso 2**: Datos de conexión a MySQL (crea las tablas automáticamente)
- **Paso 3**: Crear el usuario administrador

### 5. ⚠️ IMPORTANTE: Eliminar la carpeta `/install/`

Tras completar la instalación, **elimina la carpeta `install/`** del servidor:

```bash
rm -rf /public_html/aesan/install/
```

---

## Uso

### Importar productos desde PrestaShop

1. En PrestaShop: **Catálogo → Productos → Exportar**
   - Selecciona las columnas: ID, Referencia, Nombre, Descripción corta, Descripción
2. Entra en AESAN Checker → **Importar Excel**
3. Sube el archivo `.xlsx` exportado

### Formato del Excel de entrada

| Columna | Nombre | Ejemplo |
|---------|--------|---------|
| A | ID / id_producto | 123 |
| B | Referencia / SKU | BURGER-001 |
| C | Nombre / Name | Burger Meat de Chuletón |
| D | Descripción corta | Hamburguesa de vacuno madurado |
| E | Descripción larga | HTML del editor de PrestaShop |

> Los encabezados se detectan automáticamente si los nombres coinciden (en cualquier orden).

### Flujo de validación

1. La app analiza cada producto y detecta el tipo (carne fresca, picada, preparado, producto cárnico)
2. Muestra la tabla de resultados: ✔ completo / ✘ incompleto
3. Haz clic en ✏️ para completar los campos que faltan (wizard 5 pasos)
4. Una vez completos, selecciona los productos y **exporta el CSV para PrestaShop**

### Exportar a PrestaShop

El CSV generado contiene:
- `ID; Reference; Name; Short description; Description`
- La descripción larga incluye el contenido original **más** el bloque `<div class="info-alimentaria">` al final

Para importar en PrestaShop: **Parámetros avanzados → Importar** → selecciona "Productos".

---

## Campos AESAN por tipo de producto

| Campo | Carne fresca | Carne picada | Preparado | Prod. cárnico |
|-------|:---:|:---:|:---:|:---:|
| Denominación | ✔ | ✔ | ✔ | ✔ |
| Especie animal | ✔ | ✔ | ✔ | ✔ |
| Origen (nacido/criado/sacrificado) | ✔ | ✔ | ✔ | ✔ |
| Límites grasa y colágeno | — | ✔ | ✔ | — |
| Lista de ingredientes | — | ✔ | ✔ | ✔ |
| Alérgenos resaltados | — | ✔ | ✔ | ✔ |
| Condiciones de conservación | ✔ | ✔ | ✔ | ✔ |
| Instrucción de cocinado | — | ✔ | ✔* | — |
| Información nutricional | — | — | ✔ | ✔ |

*Solo en preparados crudos (hamburguesas, adobados, etc.)

---

## Estructura de archivos

```
aesan/
├── install/          ← Wizard instalación (eliminar tras instalar)
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── includes/
│   ├── config.php    ← Generado por el instalador
│   ├── db.php
│   ├── auth.php
│   ├── validator.php ← Lógica AESAN
│   ├── exporter.php  ← Generador HTML/CSV
│   ├── functions.php
│   └── layout.php
├── uploads/          ← Excels subidos (protegido)
├── exports/          ← CSVs generados (protegido)
├── index.php         ← Login
├── dashboard.php
├── import.php
├── productos.php
├── producto.php      ← Editor wizard
├── export.php
└── users.php
```

---

## Normativa de referencia

- Reglamento (UE) nº 1169/2011 — Información alimentaria al consumidor
- Reglamento (CE) nº 1760/2000 y 1825/2000 — Etiquetado carne vacuno
- Reglamento de Ejecución (UE) nº 1337/2013 — Origen porcino/aves/ovino
- Reglamento (CE) nº 853/2004 — Higiene productos de origen animal
- RD 126/2015 — Información alimentaria sin envasar
- Ley 34/2002 (LSSICE) — Información del prestador de servicios

---

*AESAN Checker — Plan Coordinado 1 feb – 30 abr 2026*
