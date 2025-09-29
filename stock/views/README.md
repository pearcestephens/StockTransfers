# Stock Transfers - Modular View System

This directory contains a refactored, modular view system for the Stock Transfers module. The monolithic `pack.php` file has been broken down into reusable, generic view components.

## 📁 Directory Structure

```
views/
├── components/              # Reusable UI components
│   ├── breadcrumb.php       # Generic breadcrumb navigation
│   ├── status-alert.php     # Contextual status alerts
│   ├── product-search.php   # Product search interface
│   ├── transfer-header.php  # Transfer info & actions header
│   ├── items-table.php      # Transfer items table
│   ├── pack-ship-console.php # Main dispatch console
│   └── dispatch/            # Dispatch console sub-components
│       ├── parcel-panel.php     # Package management panel
│       ├── rates-panel.php      # Rates & options panel
│       └── method-panels.php    # Alternative shipping methods
├── pack.view.php            # Main pack page view
└── receive.view.php         # Existing receive view
```

## 🧩 Component Design Philosophy

### Principles
- **Generic & Reusable**: Components work across different transfer types
- **Configuration-Driven**: Behavior controlled via config arrays
- **Separation of Concerns**: UI rendering separate from business logic
- **Accessibility**: Proper ARIA labels, semantic HTML, keyboard navigation
- **Performance**: Minimal footprint, CSS/JS auto-loaded

### Component Structure
Each component follows this pattern:

```php
<?php
/**
 * Component Name
 * 
 * Brief description of what this component does
 * 
 * Required variables:
 * - $config_name['key'] - Description
 * - $another_var - Description
 */

// Default configuration with safe fallbacks
$default_config = [
  'key1' => 'default_value',
  'key2' => false,
  'key3' => []
];

// Merge with provided config
$component_config = array_merge($default_config, $config_name ?? []);
?>

<!-- Component HTML with escaped output -->
```

## 🔧 Usage Examples

### Breadcrumb Component
```php
<?php
$breadcrumb_config = [
  'active_page' => 'Pack',
  'show_transfer_id' => true,
  'transfer_id' => $transferId,
  'custom_items' => [
    ['label' => 'Shipments', 'url' => '/shipments']
  ]
];
include __DIR__ . '/components/breadcrumb.php';
?>
```

### Status Alert Component
```php
<?php
$alert_config = [
  'type' => 'warning',
  'title' => 'Transfer Already Packaged',
  'message' => 'This transfer has been marked as packed.',
  'details' => [
    'You can still make edits',
    'Changes update the packed record',
    'No data sent to Vend until marked as sent'
  ],
  'footer_message' => 'Use "Mark as Packed & Send" when ready to dispatch.'
];
include __DIR__ . '/components/status-alert.php';
?>
```

### Product Search Component
```php
<?php
$search_config = [
  'input_placeholder' => 'Search products…',
  'show_bulk_actions' => true,
  'bulk_actions' => [
    ['id' => 'bulk-add', 'label' => 'Add Selected', 'class' => 'btn-primary']
  ]
];
include __DIR__ . '/components/product-search.php';
?>
```

### Transfer Header Component
```php
<?php
$header_config = [
  'transfer_id' => $transferId,
  'title' => 'Pack Transfer',
  'subtitle' => 'Store A → Store B',
  'description' => 'Count and finalize this consignment',
  'actions' => [
    ['id' => 'savePack', 'label' => 'Save', 'class' => 'btn-primary', 'icon' => 'fa-save']
  ],
  'metrics' => [
    ['label' => 'Items', 'id' => 'itemCount', 'value' => count($items)]
  ]
];
include __DIR__ . '/components/transfer-header.php';
?>
```

### Items Table Component
```php
<?php
$table_config = [
  'items' => $transferItems,
  'transfer_id' => $transferId,
  'destination_label' => $destinationOutlet,
  'source_stock_map' => $stockLevels,
  'show_actions' => true
];
include __DIR__ . '/components/items-table.php';
?>
```

## 🚀 Migration Guide

### From Monolithic to Modular

**Before (pack.php):**
```php
// 1129 lines of mixed logic and HTML
<?php
// Business logic
// HTML rendering
// More business logic  
// More HTML
// JavaScript
```

**After (pack-new.php + views):**
```php
<?php
// pack-new.php - Pure business logic (350 lines)
require_once __DIR__ . '/lib/pack-helpers.php';
// ... business logic ...
include __DIR__ . '/views/pack.view.php';
```

```php
<?php
// views/pack.view.php - Pure presentation (100 lines)
include __DIR__ . '/components/breadcrumb.php';
include __DIR__ . '/components/status-alert.php';
include __DIR__ . '/components/product-search.php';
// etc...
```

### Benefits
- **Maintainability**: Each component has single responsibility
- **Reusability**: Components work across receive.php, pack.php, etc.
- **Testability**: Business logic and UI completely separated
- **Performance**: Only required components loaded
- **Consistency**: Standardized UI patterns across all transfer pages

## 🎨 Styling & Assets

### CSS Organization
- **Global Styles**: `pack-extracted.css` contains extracted styles
- **Component Styles**: Each component uses consistent CSS classes
- **Auto-Loading**: `load_transfer_css()` includes all necessary styles

### JavaScript Integration
- **Boot Payload**: `window.DISPATCH_BOOT` provides data to JS
- **Event Handlers**: Components emit semantic DOM events
- **Auto-Loading**: `load_transfer_js()` includes required scripts

## 🔍 Component Reference

### Breadcrumb (`breadcrumb.php`)
**Purpose**: Navigation breadcrumbs for transfer pages  
**Config**: `$breadcrumb_config`  
**Key Options**: `active_page`, `transfer_id`, `custom_items`

### Status Alert (`status-alert.php`)  
**Purpose**: Contextual warnings, info, errors  
**Config**: `$alert_config`  
**Key Options**: `type`, `title`, `message`, `details`

### Product Search (`product-search.php`)
**Purpose**: Search and add products interface  
**Config**: `$search_config`  
**Key Options**: `bulk_actions`, `columns`, `empty_message`

### Transfer Header (`transfer-header.php`)
**Purpose**: Page title, actions, metrics display  
**Config**: `$header_config`  
**Key Options**: `actions`, `metrics`, `draft_status`

### Items Table (`items-table.php`)
**Purpose**: Editable transfer items table  
**Config**: `$table_config`  
**Key Options**: `items`, `columns`, `render_functions`

### Pack & Ship Console (`pack-ship-console.php`)
**Purpose**: Main dispatch interface  
**Config**: `$dispatch_config`  
**Key Options**: `show_courier_detail`, `methods`, `print_pool`

## 📋 Development Guidelines

### Adding New Components
1. Create component in `views/components/`
2. Follow the standard component structure
3. Provide comprehensive configuration options
4. Include accessibility attributes
5. Add to this README with usage examples

### Modifying Existing Components
1. Maintain backward compatibility
2. Update default configurations carefully
3. Test across all usage contexts
4. Update documentation

### Best Practices
- **Always escape output**: Use `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`
- **Provide fallbacks**: Default config values for all options
- **Semantic HTML**: Use appropriate tags and ARIA attributes
- **Mobile-first**: Design for small screens first
- **Performance**: Minimize DOM queries and reflows

## 🧪 Testing Components

### Manual Testing Checklist
- [ ] Component renders with minimal config
- [ ] All configuration options work correctly
- [ ] Responsive design works on mobile/desktop
- [ ] Keyboard navigation functions properly
- [ ] Screen readers announce content appropriately
- [ ] No console errors or warnings

### Integration Testing
- [ ] Component integrates properly with existing CSS
- [ ] JavaScript interactions work as expected
- [ ] Asset loading doesn't break
- [ ] Performance impact is minimal

## 🔮 Future Enhancements

### Planned Features
- **Component Library**: Expand to cover all transfer operations
- **Theme Support**: Dark mode and accessibility themes  
- **Performance**: Lazy loading for heavy components
- **Testing**: Automated component testing framework
- **Documentation**: Interactive component showcase

### Migration Roadmap
1. ✅ **Phase 1**: Extract pack.php components (Complete)
2. **Phase 2**: Apply to receive.php and other transfer pages
3. **Phase 3**: Create shared component library
4. **Phase 4**: Add advanced features (themes, lazy loading)

---

## 📞 Support

For questions about the view system:
- Check existing component examples
- Review the configuration options
- Test in the development environment first
- Document any new patterns you create

**Remember**: The goal is maximum reusability with minimal configuration complexity! 🎯