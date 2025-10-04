/**
 * Shared Modal Utilities
 * 
 * Centralized modal management
 * 
 * @module shared/modal
 */

(function(window) {
    'use strict';
    
    const Modal = {
        /**
         * Show product image in modal
         */
        showImage(src, title) {
            const modalEl = document.getElementById('productImageModal');
            const imgEl = document.getElementById('productImageModalImg');
            const titleEl = document.getElementById('productImageModalLabel');
            
            if (imgEl) imgEl.src = src;
            if (titleEl) titleEl.textContent = title;
            
            if (modalEl && window.jQuery) {
                jQuery(modalEl).modal('show');
            }
        },
        
        /**
         * Generic alert modal
         */
        alert(message, title = 'Notice') {
            // Use native alert for now, can be enhanced
            alert(`${title}\n\n${message}`);
        },
        
        /**
         * Generic confirm modal
         */
        confirm(message, title = 'Confirm') {
            return window.confirm(`${title}\n\n${message}`);
        },
    };
    
    window.SharedModal = Modal;
})(window);
