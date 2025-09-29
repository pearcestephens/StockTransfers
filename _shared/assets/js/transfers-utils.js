/* ==========================================================================
   Transfers — Shared JavaScript Utilities
   Reusable functions and utilities for all transfer components
   Requires: jQuery (>=3.x)
   ========================================================================== */

(function(window, $) {
  'use strict';

  if (!$) {
    console.error('[Transfers/Utils] jQuery is required.');
    return;
  }

  // Create namespace
  window.TransfersUtils = window.TransfersUtils || {};

  // ===== UTILITY FUNCTIONS ===== //

  /**
   * Format numbers with thousands separator
   * @param {number|string} num - Number to format
   * @param {number} decimals - Number of decimal places (default: 0)
   * @returns {string} Formatted number
   */
  TransfersUtils.formatNumber = function(num, decimals = 0) {
    const number = parseFloat(num) || 0;
    return number.toLocaleString('en-NZ', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    });
  };

  /**
   * Format currency values
   * @param {number|string} amount - Amount to format
   * @param {string} currency - Currency code (default: 'NZD')
   * @returns {string} Formatted currency
   */
  TransfersUtils.formatCurrency = function(amount, currency = 'NZD') {
    const number = parseFloat(amount) || 0;
    return new Intl.NumberFormat('en-NZ', {
      style: 'currency',
      currency: currency
    }).format(number);
  };

  /**
   * Format dates consistently across components
   * @param {string|Date} date - Date to format
   * @param {object} options - Formatting options
   * @returns {string} Formatted date
   */
  TransfersUtils.formatDate = function(date, options = {}) {
    const defaults = {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    };
    
    const formatOptions = Object.assign(defaults, options);
    const dateObj = date instanceof Date ? date : new Date(date);
    
    if (isNaN(dateObj.getTime())) {
      return 'Invalid date';
    }
    
    return dateObj.toLocaleString('en-NZ', formatOptions);
  };

  /**
   * Debounce function calls
   * @param {Function} func - Function to debounce
   * @param {number} wait - Wait time in milliseconds
   * @returns {Function} Debounced function
   */
  TransfersUtils.debounce = function(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  };

  /**
   * Show toast notification
   * @param {string} message - Message to show
   * @param {string} type - Type: success, warning, error, info
   * @param {number} duration - Duration in milliseconds (default: 4000)
   */
  TransfersUtils.showToast = function(message, type = 'info', duration = 4000) {
    const toast = $(`
      <div class="tfx-toast tfx-toast--${type}" role="alert">
        <div class="tfx-toast__content">
          <i class="tfx-toast__icon fa fa-${this.getToastIcon(type)}"></i>
          <span class="tfx-toast__message">${message}</span>
          <button class="tfx-toast__close" aria-label="Close">
            <i class="fa fa-times"></i>
          </button>
        </div>
      </div>
    `);

    // Add to container or create one
    let container = $('.tfx-toast-container');
    if (!container.length) {
      container = $('<div class="tfx-toast-container"></div>').appendTo('body');
    }
    
    container.append(toast);
    
    // Auto-hide after duration
    setTimeout(() => {
      toast.fadeOut(300, () => toast.remove());
    }, duration);
    
    // Manual close
    toast.find('.tfx-toast__close').on('click', () => {
      toast.fadeOut(300, () => toast.remove());
    });
  };

  /**
   * Get appropriate icon for toast type
   * @param {string} type - Toast type
   * @returns {string} FontAwesome icon class
   */
  TransfersUtils.getToastIcon = function(type) {
    const icons = {
      success: 'check-circle',
      warning: 'exclamation-triangle',
      error: 'times-circle',
      info: 'info-circle'
    };
    return icons[type] || 'info-circle';
  };

  /**
   * Handle AJAX errors consistently
   * @param {object} xhr - XMLHttpRequest object
   * @param {string} context - Context description for error
   */
  TransfersUtils.handleAjaxError = function(xhr, context = '') {
    let message = 'An unexpected error occurred';
    
    if (xhr.responseJSON && xhr.responseJSON.error) {
      message = xhr.responseJSON.error.message || message;
    } else if (xhr.responseText) {
      try {
        const response = JSON.parse(xhr.responseText);
        message = response.error || message;
      } catch (e) {
        // Not JSON, use status text
        message = xhr.statusText || message;
      }
    }
    
    if (context) {
      message = `${context}: ${message}`;
    }
    
    this.showToast(message, 'error');
    console.error('AJAX Error:', {xhr, context, message});
  };

  /**
   * Validate form fields
   * @param {jQuery} $form - Form element
   * @returns {object} Validation result with isValid and errors
   */
  TransfersUtils.validateForm = function($form) {
    const errors = [];
    
    // Check required fields
    $form.find('[required]').each(function() {
      const $field = $(this);
      const value = $field.val().trim();
      const label = $field.attr('aria-label') || $field.attr('name') || 'Field';
      
      if (!value) {
        errors.push(`${label} is required`);
        $field.addClass('is-invalid');
      } else {
        $field.removeClass('is-invalid');
      }
    });
    
    // Check email fields
    $form.find('[type="email"]').each(function() {
      const $field = $(this);
      const value = $field.val().trim();
      
      if (value && !TransfersUtils.isValidEmail(value)) {
        errors.push('Please enter a valid email address');
        $field.addClass('is-invalid');
      }
    });
    
    return {
      isValid: errors.length === 0,
      errors: errors
    };
  };

  /**
   * Check if email is valid
   * @param {string} email - Email to validate
   * @returns {boolean} Is valid email
   */
  TransfersUtils.isValidEmail = function(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
  };

  /**
   * Generate unique ID for components
   * @param {string} prefix - ID prefix
   * @returns {string} Unique ID
   */
  TransfersUtils.generateId = function(prefix = 'tfx') {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
  };

  /**
   * Copy text to clipboard
   * @param {string} text - Text to copy
   * @returns {Promise} Promise resolving when copy is complete
   */
  TransfersUtils.copyToClipboard = function(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    } else {
      // Fallback for older browsers
      return new Promise((resolve, reject) => {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
          document.execCommand('copy');
          textArea.remove();
          resolve();
        } catch (error) {
          textArea.remove();
          reject(error);
        }
      });
    }
  };

  // ===== COMPONENT HELPERS ===== //

  /**
   * Initialize draft status component
   * @param {string} selector - CSS selector for draft status element
   * @param {object} options - Configuration options
   */
  TransfersUtils.initDraftStatus = function(selector, options = {}) {
    const defaults = {
      autoSave: false,
      saveInterval: 10000, // 10 seconds
      states: {
        idle: 'Idle',
        saving: 'Saving...',
        saved: 'Saved',
        error: 'Error'
      }
    };
    
    const config = Object.assign(defaults, options);
    const $element = $(selector);
    
    if (!$element.length) return;
    
    // Store config on element
    $element.data('draft-config', config);
    
    // Auto-save functionality
    if (config.autoSave && config.saveCallback) {
      setInterval(() => {
        TransfersUtils.updateDraftStatus(selector, 'saving');
        config.saveCallback()
          .then(() => TransfersUtils.updateDraftStatus(selector, 'saved'))
          .catch(() => TransfersUtils.updateDraftStatus(selector, 'error'));
      }, config.saveInterval);
    }
  };

  /**
   * Update draft status display
   * @param {string} selector - CSS selector for draft status element
   * @param {string} state - New state (idle, saving, saved, error)
   * @param {string} customText - Custom status text (optional)
   */
  TransfersUtils.updateDraftStatus = function(selector, state, customText = null) {
    const $element = $(selector);
    const config = $element.data('draft-config') || {};
    
    // Update classes
    $element
      .removeClass('status-idle status-saving status-saved status-error')
      .addClass(`status-${state}`)
      .attr('data-state', state);
    
    // Update text
    const text = customText || config.states[state] || state;
    $element.find('.pill-text').text(text);
    
    // Update last saved timestamp
    if (state === 'saved') {
      const timestamp = TransfersUtils.formatDate(new Date(), {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });
      $('#draft-last-saved').text(timestamp);
    }
  };

  // ===== INITIALIZATION ===== //
  
  // Set up global AJAX error handling
  $(document).ajaxError(function(event, xhr, settings) {
    if (xhr.status !== 200) {
      TransfersUtils.handleAjaxError(xhr, 'Request failed');
    }
  });

  // Add toast container styles if not present
  if (!$('#tfx-toast-styles').length) {
    $('<style id="tfx-toast-styles">')
      .text(`
        .tfx-toast-container {
          position: fixed;
          top: 20px;
          right: 20px;
          z-index: 9999;
        }
        .tfx-toast {
          margin-bottom: 10px;
          min-width: 300px;
          max-width: 500px;
        }
      `)
      .appendTo('head');
  }

  // Ready
  console.log('[Transfers/Utils] Initialized successfully');

})(window, window.jQuery);