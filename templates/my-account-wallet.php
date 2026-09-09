<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @var float $balance
 * @var array $history
 * @var float $pending_balance
 */

//  O PHP define o ESTADO (classe), não o VISUAL (cor).
$balance_status_class = $balance >= 0 ? 'wcw-border-positive' : 'wcw-border-negative';
$balance_text_class   = $balance >= 0 ? 'wcw-status-positive' : 'wcw-status-negative';

$formatted_balance = number_format( $balance, 2, ',', '.' );
$formatted_pending = isset( $pending_balance ) ? number_format( $pending_balance, 2, ',', '.' ) : '0,00';
?>

<div class="wcw-account-wrapper">
    <h3 class="wcw-main-title"><?php esc_html_e( 'Minha Carteira', 'digital-wallet-for-woocommerce' ); ?></h3>
    <p class="wcw-subtitle"><?php esc_html_e( 'Acompanhe seu saldo e adicione créditos para suas próximas compras.', 'digital-wallet-for-woocommerce' ); ?></p>

    <!-- Card de Saldo Proporcional -->
    <div class="wcw-balance-card <?php echo esc_attr( $balance_status_class ); ?>">
        <div class="wcw-balance-info">
            <span class="wcw-balance-label"><?php esc_html_e( 'Saldo Disponível', 'digital-wallet-for-woocommerce' ); ?></span>
            <span class="wcw-balance-amount <?php echo esc_attr( $balance_text_class ); ?>">
                R$ <?php echo esc_html( $formatted_balance ); ?>
            </span>
            
            <?php if ( ! empty( $pending_balance ) && $pending_balance > 0 ) : ?>
                <!-- A UI do Shadow Ledger -->
                <div class="wcw-pending-box">
                    <span class="wcw-pending-label"><?php esc_html_e( 'Aguardando Pagamento/Envio', 'digital-wallet-for-woocommerce' ); ?></span>
                    <span class="wcw-pending-value">+ R$ <?php echo esc_html( $formatted_pending ); ?></span>
                </div>
            <?php endif; ?>

            <?php if ( $balance < 0 ) : ?>
                <span class="wcw-balance-warning"><?php esc_html_e( 'Saldo negativo devido a estorno recente.', 'digital-wallet-for-woocommerce' ); ?></span>
            <?php endif; ?>
        </div>
        
       <!-- Formulário Compacto de Recarga (Condicional) -->
        <?php if ( 'yes' === get_option( 'wcw_wallet_recharge_status', 'yes' ) ) : ?>
            <div class="wcw-recharge-box">
                <span class="wcw-recharge-label"><?php esc_html_e( 'Adicionar créditos à carteira:', 'digital-wallet-for-woocommerce' ); ?></span>
                <form method="POST" action="" class="wcw-recharge-form">
                    <?php wp_nonce_field( 'wcw_adicionar_saldo_nonce', 'wcw_saldo_security' ); ?>
                    <div class="wcw-input-group">
                        <span class="wcw-currency-symbol">R$</span>
                        <input type="text" name="wcw_valor_recarga" placeholder="0,00" required pattern="[0-9,.]*">
                    </div>
                    <button type="submit" name="wcw_add_credito_submit" class="button wcw-btn-recharge">
                        <?php esc_html_e( 'Adicionar', 'digital-wallet-for-woocommerce' ); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div> 

    <!-- Tabela de Transações Compacta -->
    <h4 class="wcw-table-title"><?php esc_html_e( 'Histórico de Transações', 'digital-wallet-for-woocommerce' ); ?></h4>
    <div class="wcw-table-responsive">
        <table class="woocommerce-orders-table shop_table shop_table_responsive account-orders-table wcw-custom-table">
            <thead>
                <tr>
                    <th class="woocommerce-orders-table__header"><span><?php esc_html_e( 'Data', 'digital-wallet-for-woocommerce' ); ?></span></th>
                    <th class="woocommerce-orders-table__header"><span><?php esc_html_e( 'Descrição', 'digital-wallet-for-woocommerce' ); ?></span></th>
                    <th class="woocommerce-orders-table__header wcw-text-right"><span><?php esc_html_e( 'Valor', 'digital-wallet-for-woocommerce' ); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $history ) ) : ?>
                    <?php foreach ( $history as $txn ) : 
                        $data_formatada = date_i18n( 'd/m/Y H:i', strtotime( $txn['data'] ) );
                        
                        $sinal = ( 'credito' === $txn['tipo'] ) ? '+' : '-';
                        
                        // Define as cores do valor baseado no STATUS real do banco de dados
                        if ( 'pending' === $txn['status'] ) {
                            $txn_text_class = 'wcw-pending-value'; // Cor Laranja para valores pendentes
                        } else {
                            $txn_text_class = ( 'credito' === $txn['tipo'] ) ? 'wcw-status-positive' : 'wcw-status-negative';
                        }
                        
                        $badge_html  = '';
                        $badge_class = '';
                        $badge_text  = '';
                        
                        // Lógica Sênior: Baseada em Status e Tipo
                        if ( 'pending' === $txn['status'] ) {
                            $badge_class = 'wcw-badge-recharge'; // Alerta / Pendente
                            $badge_text  = 'Pendente';
                        } elseif ( 'credito' === $txn['tipo'] ) {
                            if ( strpos( strtolower( $txn['descricao'] ), 'recarga' ) !== false ) {
                                $badge_class = 'wcw-badge-recharge';
                                $badge_text  = 'Recarga';
                            } else {
                                $badge_class = 'wcw-badge-cashback';
                                $badge_text  = 'Cashback';
                            }
                        } elseif ( 'debito' === $txn['tipo'] ) {
                            if ( strpos( strtolower( $txn['descricao'] ), 'estorno' ) !== false || strpos( strtolower( $txn['descricao'] ), 'reversão' ) !== false ) {
                                $badge_class = 'wcw-badge-refund';
                                $badge_text  = 'Estorno';
                            } else {
                                $badge_class = 'wcw-badge-purchase';
                                $badge_text  = 'Compra';
                            }
                        }

                        if ( ! empty( $badge_text ) ) {
                            $badge_html = '<span class="wcw-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span>';
                        }
                    ?>
                        <tr class="woocommerce-orders-table__row">
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Data', 'digital-wallet-for-woocommerce' ); ?>">
                                <?php echo esc_html( $data_formatada ); ?>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Descrição', 'digital-wallet-for-woocommerce' ); ?>">
                                <?php echo wp_kses_post( $badge_html ) . esc_html( $txn['descricao'] ); ?>
                            </td>
                            <td class="woocommerce-orders-table__cell wcw-text-right wcw-font-bold <?php echo esc_attr( $txn_text_class ); ?>" data-title="<?php esc_attr_e( 'Valor', 'digital-wallet-for-woocommerce' ); ?>">
                                <?php echo esc_html( $sinal ); ?> R$ <?php echo esc_html( number_format( $txn['valor'], 2, ',', '.' ) ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="3" class="wcw-empty-ledger">
                            <em><?php esc_html_e( 'Nenhuma transação encontrada.', 'digital-wallet-for-woocommerce' ); ?></em>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>