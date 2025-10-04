/**
 * Shared Toast Notifications
 * 
 * Simple toast notification system
 * 
 * @module shared/toast
 */

(function(window) {
    'use strict';
    
    const Toast = {
        show(message, type = 'info', duration = 3000) {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.textContent = message;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${this.getBackgroundColor(type)};
                color: white;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                z-index: 9999;
                animation: slideIn 0.3s ease-out;
            `;
            
            document.body.appendChild(toast);
            
            // Auto-remove
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },
        
        getBackgroundColor(type) {
            const colors = {
                success: '#28a745',
                error: '#dc3545',
                warning: '#ffc107',
                info: '#17a2b8',
            };
            return colors[type] || colors.info;
        },
        
        success(message, duration) {
            this.show(message, 'success', duration);
        },
        
        error(message, duration) {
            this.show(message, 'error', duration);
        },
        
        warning(message, duration) {
            this.show(message, 'warning', duration);
        },
        
        info(message, duration) {
            this.show(message, 'info', duration);
        },
    };
    
    window.SharedToast = Toast;
})(window);
