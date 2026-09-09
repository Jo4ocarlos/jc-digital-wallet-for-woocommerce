jQuery(function($){
    'use strict';
    
    // Atualiza os totais do checkout automaticamente ao clicar na checkbox da carteira
    $('body').on('change', '#wcw_checkbox_saldo', function(){
        $('body').trigger('update_checkout');
    });
});