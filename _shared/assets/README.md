# Transfers Shared Assets System

This directory contains shared CSS, JavaScript, and other assets that are used across all transfer modules (stock, inventory, etc.). The shared assets system provides a foundation for consistent UI components and functionality.

## Directory Structure

```
_shared/assets/
├── css/
│   ├── transfers-variables.css      # CSS custom properties & design tokens
│   ├── transfers-components.css     # Component library styles
│   └── transfers-common.css         # Base utilities & overrides
├── js/
│   ├── transfers-utils.js          # Shared JavaScript utilities
│   └── transfers-common.js         # Common functionality
└── README.md                       # This file
```

## CSS Architecture

### 1. Design System (`transfers-variables.css`)
- **CSS Custom Properties**: Centralized design tokens for colors, spacing, typography
- **Brand Colors**: Primary, secondary, success, warning, danger, info variations
- **Spacing Scale**: Consistent spacing system (xs, sm, md, lg, xl, xxl)
- **Typography**: Font families, sizes, weights, line heights
- **Component Variables**: Specific tokens for buttons, forms, cards, etc.

### 2. Component Library (`transfers-components.css`)
- **Component Styles**: `.tfx-*` prefixed classes for modular components
- **Layout Utilities**: Gap classes, responsive adjustments
- **Interactive Elements**: Buttons, forms, status indicators
- **Uses Variables**: All styles reference design tokens from `transfers-variables.css`

### 3. Base Utilities (`transfers-common.css`)
- **Bootstrap Overrides**: Safe modifications to Bootstrap defaults
- **Utility Classes**: Common patterns used across modules
- **Legacy Support**: Maintains backward compatibility

## JavaScript Architecture

### 1. Core Utilities (`transfers-utils.js`)
- **TransfersUtils Namespace**: Global utilities accessible across all modules
- **Format Functions**: Number, currency, date formatting with NZ locales
- **UI Helpers**: Toast notifications, form validation, clipboard operations
- **AJAX Utilities**: Consistent error handling, request patterns
- **Component Helpers**: Draft status management, initialization patterns

### 2. Common Functionality (`transfers-common.js`)
- **jQuery Extensions**: Custom plugins and extensions
- **Global Event Handlers**: Common patterns for form submission, validation
- **Initialization**: Auto-setup for common UI patterns

## Asset Loading System

The `AssetLoader.php` class provides automatic asset discovery and loading:

### Basic Usage
```php
// Load module-specific assets only
$loader = AssetLoader::forStockTransfers();
echo $loader->loadAll();
```

### Shared + Module Loading
```php
// Load shared assets first, then module-specific
$loader = AssetLoader::forStockTransfers();
echo $loader->loadSharedAndModule();
```

### Quick Helper
```php
// One-liner for shared + module loading
echo AssetLoader::loadStockWithShared();
```

## Loading Order

The system ensures proper cascading by loading assets in this order:

1. **Shared CSS** (`_shared/assets/css/`)
   - `transfers-variables.css` (design tokens)
   - `transfers-components.css` (component styles)  
   - `transfers-common.css` (utilities)

2. **Module CSS** (`stock/assets/css/`)
   - Module-specific styles that extend/override shared styles

3. **Shared JavaScript** (`_shared/assets/js/`)
   - `transfers-utils.js` (core utilities)
   - `transfers-common.js` (common functionality)

4. **Module JavaScript** (`stock/assets/js/`)
   - Module-specific scripts that depend on shared utilities

## Component Naming

### CSS Classes
- **Shared Components**: `.tfx-*` prefix (e.g., `.tfx-product-search`)
- **Module Components**: `.module-*` prefix (e.g., `.stock-pack-form`)
- **Utility Classes**: `.u-*` prefix (e.g., `.u-text-center`)

### JavaScript
- **Global Namespace**: `TransfersUtils.*`
- **Module Namespaces**: `StockTransfers.*`, `InventoryTransfers.*`

## CSS Custom Properties

All shared styles use CSS custom properties for consistency:

```css
/* Good - Uses design tokens */
.my-component {
  padding: var(--spacing-md);
  color: var(--primary);
  border-radius: var(--border-radius-md);
}

/* Avoid - Hard-coded values */
.my-component {
  padding: 16px;
  color: #007bff;
  border-radius: 6px;
}
```

## Adding New Shared Components

### 1. CSS Component
```css
/* In transfers-components.css */
.tfx-my-component {
  background: var(--white);
  border-radius: var(--border-radius-md);
  padding: var(--spacing-md);
  box-shadow: var(--shadow-sm);
}

.tfx-my-component__header {
  border-bottom: 1px solid var(--border-light);
  margin-bottom: var(--spacing-sm);
  padding-bottom: var(--spacing-sm);
}
```

### 2. JavaScript Utilities
```javascript
// In transfers-utils.js
TransfersUtils.initMyComponent = function(selector, options) {
  const defaults = {
    autoSave: false,
    validateOnChange: true
  };
  
  const config = Object.assign(defaults, options);
  const $element = $(selector);
  
  // Component initialization logic
};
```

### 3. Usage in Views
```php
<!-- In any transfer module view -->
<div class="tfx-my-component" id="my-component-instance">
  <div class="tfx-my-component__header">
    <h4>Component Title</h4>
  </div>
  <!-- Component content -->
</div>

<script>
// Initialize with shared utilities  
TransfersUtils.initMyComponent('#my-component-instance', {
  autoSave: true
});
</script>
```

## Performance Considerations

- **File Size**: Keep CSS files under 25KB each for optimal loading
- **Versioning**: AssetLoader uses file modification time for cache busting
- **Minification**: Consider minifying for production deployment
- **Critical CSS**: Inline critical above-the-fold styles when needed

## Browser Support

- **Modern Browsers**: Chrome 70+, Firefox 65+, Safari 12+, Edge 79+
- **CSS Features**: CSS Custom Properties, CSS Grid, Flexbox
- **JavaScript**: ES6+ features, requires transpilation for older browsers

## Development Guidelines

### CSS
- Use BEM methodology for component class naming
- Leverage CSS custom properties from `transfers-variables.css`
- Mobile-first responsive design
- Maintain WCAG 2.1 AA accessibility standards

### JavaScript
- Use strict mode (`'use strict'`)
- Namespace all utilities under `TransfersUtils`
- Provide fallbacks for older browser APIs
- Include JSDoc comments for all public functions

### File Organization
- One component per CSS rule block
- Logical grouping within files (layout, interactive, utilities)
- Consistent indentation and formatting
- Descriptive comments for complex logic

## Testing

### CSS Testing
```bash
# Lint CSS files
csslint _shared/assets/css/transfers-*.css

# Check for unused styles
purifycss stock/**/*.php _shared/assets/css/transfers-*.css
```

### JavaScript Testing
```javascript
// Test utilities
console.assert(TransfersUtils.formatNumber(1234) === '1,234');
console.assert(TransfersUtils.formatCurrency(99.99) === '$99.99');
```

## Migration from Module-Specific Assets

When moving styles from module assets to shared assets:

1. **Identify Reusable Patterns**: Look for repeated CSS patterns across modules
2. **Extract to Variables**: Convert hard-coded values to CSS custom properties
3. **Create Component Classes**: Use `.tfx-*` naming convention
4. **Update AssetLoader**: Use `loadSharedAndModule()` method
5. **Test All Modules**: Ensure changes don't break existing functionality

## Future Enhancements

- **CSS-in-JS Integration**: For dynamic theming
- **Design Token Generation**: Automated token updates from design system
- **Component Documentation**: Interactive style guide
- **Performance Monitoring**: Asset loading metrics
- **A/B Testing**: Style variation support