<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * @var int $recharge_product_id
 * @var array $product_categories
 */
$selected_categories = get_option( 'wcw_cashback_categories', [] );
$selected_categories = is_array( $selected_categories ) ? $selected_categories : [];
$lowest_package_rate = get_option( 'lowest_package_rate', '0.01' );
?>
<div class="wrap wcw-admin-container">
    <h1>
        <span class="dashicons dashicons-wallet wcw-header-icon"></span> 
        <?php esc_html_e( 'Carteira Digital & Cashback', 'woo-digital-wallet' ); ?>
    </h1>
    
    <div class="wcw-admin-wrap wcw-admin-card">
        <div class="wcw-admin-notice-box">
            <p>
                <strong><?php esc_html_e( 'Produto de Recarga Automático:', 'woo-digital-wallet' ); ?></strong> 
                <?php esc_html_e( 'O sistema já criou e gerencia um produto oculto de recarga', 'woo-digital-wallet' ); ?> 
                (ID: <code><?php echo esc_html( $recharge_product_id ); ?></code>). <?php esc_html_e( 'Você não precisa se preocupar com isso.', 'woo-digital-wallet' ); ?>
            </p>
        </div>

    
        <div class="wcw-admin-notice-box wcw-notice-success">
            <p class="wcw-text-highlight">
                <strong>💡 <?php esc_html_e( 'Como exibir o saldo na loja?', 'woo-digital-wallet' ); ?></strong><br>
                <?php esc_html_e( 'Copie o código abaixo e cole em qualquer página, post ou construtor visual (como o Elementor) para exibir a carteira do cliente:', 'woo-digital-wallet' ); ?>
            </p>
            <p>
                <code class="wcw-code-snippet">[wcw_saldo_carteira]</code>
            </p>
            <p class="wcw-text-muted">
                <em><?php esc_html_e( 'Dica: Você pode customizar as cores diretamente no shortcode, ex:', 'woo-digital-wallet' ); ?> <code>[wcw_saldo_carteira cor_titulo="#000000" cor_valor="#ff0000"]</code></em>
            </p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'wcw_wallet_options_group' ); ?>

            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Regras da Carteira', 'woo-digital-wallet' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Recarga Manual', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <select name="wcw_wallet_recharge_status" class="regular-text">
                            <option value="yes" <?php selected( get_option('wcw_wallet_recharge_status', 'yes'), 'yes' ); ?>>✅ <?php esc_html_e( 'Permitir (Cliente pode adicionar saldo)', 'woo-digital-wallet' ); ?></option>
                            <option value="no" <?php selected( get_option('wcw_wallet_recharge_status', 'yes'), 'no' ); ?>>❌ <?php esc_html_e( 'Bloquear (Apenas Cashback gera saldo)', 'woo-digital-wallet' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Desative se você quiser que a carteira funcione estritamente como um programa de fidelidade/cashback.', 'woo-digital-wallet' ); ?></p>
                    </td>
                </tr>
            </table>
            
            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Regras do Cashback', 'woo-digital-wallet' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Status do Cashback', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <select name="wcw_cashback_status" class="regular-text">
                            <option value="yes" <?php selected( get_option('wcw_cashback_status', 'yes'), 'yes' ); ?>>✅ <?php esc_html_e( 'Ativado', 'woo-digital-wallet' ); ?></option>
                            <option value="no" <?php selected( get_option('wcw_cashback_status', 'yes'), 'no' ); ?>>❌ <?php esc_html_e( 'Pausado', 'woo-digital-wallet' ); ?></option>
                        </select>
                    </td>
                </tr>
               <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Porcentagem (%)', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <input type="number" step="<?php echo esc_attr( $lowest_package_rate ); ?>" name="wcw_cashback_percent" value="<?php echo esc_attr( get_option('wcw_cashback_percent', 5) ); ?>" class="small-text" /> %
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Valor Mín. do Pedido', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <input type="number" step="<?php echo esc_attr( $lowest_package_rate ); ?>" name="wcw_cashback_min_val" value="<?php echo esc_attr( get_option('wcw_cashback_min_val', 0) ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'O cliente só ganha cashback se gastar mais que este valor. Deixe 0 para todas as compras.', 'woo-digital-wallet' ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Categorias Elegíveis', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <select name="wcw_cashback_categories[]" multiple="multiple" class="wc-enhanced-select regular-text" data-placeholder="<?php esc_attr_e( 'Todas as categorias', 'woo-digital-wallet' ); ?>">
                            <?php foreach ( $product_categories as $category ) : ?>
                                <?php $is_selected = in_array( $category->term_id, $selected_categories, true ); ?>
                                <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $is_selected, true ); ?>>
                                    <?php echo esc_html( $category->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Se vazio, todas as categorias geram cashback. Selecione categorias específicas para restringir.', 'woo-digital-wallet' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Visual do Widget de Saldo', 'woo-digital-wallet' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Cor do Título', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <input type="text" name="wcw_shortcode_color_title" value="<?php echo esc_attr( get_option('wcw_shortcode_color_title', '#333333') ); ?>" class="wccm-color-picker" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Cor do Valor', 'woo-digital-wallet' ); ?></label></th>
                    <td>
                        <input type="text" name="wcw_shortcode_color_value" value="<?php echo esc_attr( get_option('wcw_shortcode_color_value', '#2271b1') ); ?>" class="wccm-color-picker" />
                    </td>
                </tr>
            </table>
            
            <?php submit_button( __( 'Salvar Regras', 'woo-digital-wallet' ), 'primary' ); ?>
        </form>
    </div>
</div>