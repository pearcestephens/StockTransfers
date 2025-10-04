# (Consolidated) 🚀 Auto Asset Loading System

> Core concepts and usage patterns now captured in `TRANSFERS_MODULE_ARCHITECTURE.md` (§4). This document is legacy depth; update only if loader internals drastically change.

## Overview
The new asset loading system automatically discovers and includes all CSS and JS files from the `assets/` directories, eliminating the need to manually manage `<link>` and `<script>` tags.

## ✅ What It Does

- **Auto-Discovery**: Scans `assets/css/` and `assets/js/` directories
- **Smart Versioning**: Uses file modification time for cache busting (`?v=1727598234`)
- **Proper Loading**: CSS in `<head>`, JS with `defer` attribute  
- **Consistent Order**: Files loaded alphabetically for predictable behavior
- **Error Handling**: Gracefully handles missing directories
- **Configurable**: Supports exclusions and custom loading options

## 🔧 Usage

### Simple Usage (loads everything):
```php
<?php
require_once __DIR__ . '/../_shared/Autoload.php';

echo load_transfer_assets(); // Load all CSS + JS
```

### Separate CSS and JS:
```php
echo load_transfer_css();  // Just CSS files
echo load_transfer_js();   // Just JS files
```

### With Exclusions:
```php
echo load_transfer_assets(
    cssExclusions: ['debug.css', 'test.css'],
    jsExclusions: ['development.js']
);
```

### Debug What Files Are Found:
```php
print_r(debug_transfer_assets());
```

## 📁 Current File Discovery

**CSS Files Automatically Loaded:**
- `dispatch.css` - Main UI framework
- `pack-extracted.css` - Pack.php extracted styles  
- `ship-ui.css` - Shipping components
- `transfers-common.css` - Common utilities
- `transfers-pack.css` - Pack workflow
- `transfers-pack-inline.css` - Additional pack styles
- `transfers-receive.css` - Receive workflow

**JS Files Automatically Loaded:**
- `dispatch.js` - Main dispatch functionality
- `pack-draft-status.js` - Draft status indicator
- `pack-product-search.js` - Product search
- `ship-ui.js` - Shipping UI components  
- `transfers-common.js` - Common utilities
- `transfers-pack.js` - Pack workflow

## 🎯 Benefits

1. **No Manual Management**: Add a new CSS/JS file → it's automatically included
2. **Proper Cache Busting**: File modification time versioning 
3. **Clean Code**: No more long lists of `<link>` and `<script>` tags
4. **Maintainable**: Central configuration and easy exclusions
5. **Performance**: Consistent loading order and proper attributes

## 🔧 Advanced Configuration

```php
use Modules\Transfers\Shared\Core\AssetLoader;

$loader = new AssetLoader(
    basePath: '/path/to/assets',
    baseUrl: 'https://staff.vapeshed.co.nz/modules/transfers/',
    cssExclusions: ['development.css'],
    jsExclusions: ['test.js'],
    useFileMtimeVersioning: true
);

echo $loader->loadAll();
```

## ✨ Example Output

**Before (manual):**
```html
<link rel="stylesheet" href="...css/dispatch.css?v=1.2">
<link rel="stylesheet" href="...css/pack-extracted.css?v=1.0">
<script src="...js/dispatch.js?v=1.2" defer></script>
<script src="...js/transfers-common.js?v=1" defer></script>
```

**After (auto):**
```php
<?= load_transfer_assets(); ?>
```

**Generates:**
```html
<!-- Auto-loaded CSS files from assets/css -->
<link rel="stylesheet" href="https://staff.vapeshed.co.nz/modules/transfers/assets/css/dispatch.css?v=1727598234">
<link rel="stylesheet" href="https://staff.vapeshed.co.nz/modules/transfers/assets/css/pack-extracted.css?v=1727598235">
<!-- ... all other CSS files ... -->

<!-- Auto-loaded JS files from assets/js -->
<script src="https://staff.vapeshed.co.nz/modules/transfers/assets/js/dispatch.js?v=1727598236" defer></script>
<script src="https://staff.vapeshed.co.nz/modules/transfers/assets/js/pack-draft-status.js?v=1727598237" defer></script>
<!-- ... all other JS files ... -->
```

## 🎉 Result

**pack.php went from 8+ manual asset includes to just 2 function calls:**
- `<?= load_transfer_css(); ?>`
- `<?= load_transfer_js(); ?>`

All future CSS/JS files will be automatically included! 🚀