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
        <?php esc_html_e( 'Carteira Digital & Cashback', 'jc-digital-wallet-for-woocommerce' ); ?>
    </h1>
    
    <div class="wcw-admin-wrap wcw-admin-card">
        <div class="wcw-admin-notice-box">
            <p>
                <strong><?php esc_html_e( 'Produto de Recarga Automático:', 'jc-digital-wallet-for-woocommerce' ); ?></strong> 
                <?php esc_html_e( 'O sistema já criou e gerencia um produto oculto de recarga', 'jc-digital-wallet-for-woocommerce' ); ?> 
                (ID: <code><?php echo esc_html( $recharge_product_id ); ?></code>). <?php esc_html_e( 'Você não precisa se preocupar com isso.', 'jc-digital-wallet-for-woocommerce' ); ?>
            </p>
        </div>

    
        <div class="wcw-admin-notice-box wcw-notice-success">
            <p class="wcw-text-highlight">
                <strong>💡 <?php esc_html_e( 'Como exibir o saldo na loja?', 'jc-digital-wallet-for-woocommerce' ); ?></strong><br>
                <?php esc_html_e( 'Copie o código abaixo e cole em qualquer página, post ou construtor visual (como o Elementor) para exibir a carteira do cliente:', 'jc-digital-wallet-for-woocommerce' ); ?>
            </p>
            <p>
                <code class="wcw-code-snippet">[wcw_saldo_carteira]</code>
            </p>
            <p class="wcw-text-muted">
                <em><?php esc_html_e( 'Dica: Você pode customizar as cores diretamente no shortcode, ex:', 'jc-digital-wallet-for-woocommerce' ); ?> <code>[wcw_saldo_carteira cor_titulo="#000000" cor_valor="#ff0000"]</code></em>
            </p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'wcw_wallet_options_group' ); ?>

            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Regras da Carteira', 'jc-digital-wallet-for-woocommerce' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Recarga Manual', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <select name="wcw_wallet_recharge_status" class="regular-text">
                            <option value="yes" <?php selected( get_option('wcw_wallet_recharge_status', 'yes'), 'yes' ); ?>>✅ <?php esc_html_e( 'Permitir (Cliente pode adicionar saldo)', 'jc-digital-wallet-for-woocommerce' ); ?></option>
                            <option value="no" <?php selected( get_option('wcw_wallet_recharge_status', 'yes'), 'no' ); ?>>❌ <?php esc_html_e( 'Bloquear (Apenas Cashback gera saldo)', 'jc-digital-wallet-for-woocommerce' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Desative se você quiser que a carteira funcione estritamente como um programa de fidelidade/cashback.', 'jc-digital-wallet-for-woocommerce' ); ?></p>
                    </td>
                </tr>
            </table>
            
            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Regras do Cashback', 'jc-digital-wallet-for-woocommerce' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Status do Cashback', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <select name="wcw_cashback_status" class="regular-text">
                            <option value="yes" <?php selected( get_option('wcw_cashback_status', 'yes'), 'yes' ); ?>>✅ <?php esc_html_e( 'Ativado', 'jc-digital-wallet-for-woocommerce' ); ?></option>
                            <option value="no" <?php selected( get_option('wcw_cashback_status', 'yes'), 'no' ); ?>>❌ <?php esc_html_e( 'Pausado', 'jc-digital-wallet-for-woocommerce' ); ?></option>
                        </select>
                    </td>
                </tr>
               <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Porcentagem (%)', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <input type="number" step="<?php echo esc_attr( $lowest_package_rate ); ?>" name="wcw_cashback_percent" value="<?php echo esc_attr( get_option('wcw_cashback_percent', 5) ); ?>" class="small-text" /> %
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Valor Mín. do Pedido', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <input type="number" step="<?php echo esc_attr( $lowest_package_rate ); ?>" name="wcw_cashback_min_val" value="<?php echo esc_attr( get_option('wcw_cashback_min_val', 0) ); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e( 'O cliente só ganha cashback se gastar mais que este valor. Deixe 0 para todas as compras.', 'jc-digital-wallet-for-woocommerce' ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Categorias Elegíveis', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <select name="wcw_cashback_categories[]" multiple="multiple" class="wc-enhanced-select regular-text" data-placeholder="<?php esc_attr_e( 'Todas as categorias', 'jc-digital-wallet-for-woocommerce' ); ?>">
                            <?php foreach ( $product_categories as $category ) : ?>
                                <?php $is_selected = in_array( $category->term_id, $selected_categories, true ); ?>
                                <option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $is_selected, true ); ?>>
                                    <?php echo esc_html( $category->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Se vazio, todas as categorias geram cashback. Selecione categorias específicas para restringir.', 'jc-digital-wallet-for-woocommerce' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="wcw-admin-section-title"><?php esc_html_e( 'Visual do Widget de Saldo', 'jc-digital-wallet-for-woocommerce' ); ?></h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Cor do Título', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <input type="text" name="wcw_shortcode_color_title" value="<?php echo esc_attr( get_option('wcw_shortcode_color_title', '#333333') ); ?>" class="wccm-color-picker" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label><?php esc_html_e( 'Cor do Valor', 'jc-digital-wallet-for-woocommerce' ); ?></label></th>
                    <td>
                        <input type="text" name="wcw_shortcode_color_value" value="<?php echo esc_attr( get_option('wcw_shortcode_color_value', '#2271b1') ); ?>" class="wccm-color-picker" />
                    </td>
                </tr>
            </table>
            
            <?php submit_button( __( 'Salvar Regras', 'jc-digital-wallet-for-woocommerce' ), 'primary' ); ?>
        </form>
    </div>
</div>