jQuery(function($) {
    'use strict';

    // Inicializa o Color Picker Nativo do WP nas configurações da carteira
    if (typeof $.fn.wpColorPicker === 'function') {
        $('.wccm-color-picker').wpColorPicker();
    }
});