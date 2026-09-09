<?php
namespace WooDigitalWallet\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WalletManager {

    /**
     * Retorna APENAS o saldo LIBERADO para uso.
     */
    public static function get_balance( $user_id ) {
        if ( ! $user_id ) return 0.00;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        // Ignora sumariamente qualquer coisa que não seja 'cleared'
        $query = $wpdb->prepare(
            "SELECT SUM(
                CASE 
                    WHEN type = 'credito' THEN amount 
                    WHEN type = 'debito' THEN -amount 
                    ELSE 0 
                END
            ) FROM {$table} WHERE user_id = %d AND status = 'cleared'",
            $user_id
        );

        $saldo = $wpdb->get_var( $query );
        return round( (float) $saldo, 2 );
    }

    /**
     * Retorna o saldo PENDENTE (Shadow Ledger).
     */
    public static function get_pending_balance( $user_id ) {
        if ( ! $user_id ) return 0.00;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $query = $wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} WHERE user_id = %d AND type = 'credito' AND status = 'pending'",
            $user_id
        );

        $saldo = $wpdb->get_var( $query );
        return round( (float) $saldo, 2 );
    }

    /**
     * Adiciona fundos à carteira, aceitando provisionamento (pending).
     */
    public static function add_funds( $user_id, $amount, $description, $order_id = 0, $status = 'cleared' ) {
        if ( ! $user_id || $amount <= 0 ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';
        $amount = round( (float) $amount, 2 );
        
        $status = in_array( $status, ['cleared', 'pending', 'cancelled'] ) ? $status : 'cleared';

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'     => $user_id,
                'order_id'    => $order_id,
                'type'        => 'credito',
                'amount'      => $amount,
                'description' => sanitize_text_field( $description ),
                'status'      => $status
            ],
            [ '%d', '%d', '%s', '%f', '%s', '%s' ]
        );

        return $inserted ? self::get_balance( $user_id ) : false;
    }

    /**
     * Deduz fundos da carteira (Apenas do saldo liberado).
     */
    public static function deduct_funds( $user_id, $amount, $description, $order_id = 0 ) {
        if ( ! $user_id || $amount <= 0 ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';
        $amount = round( (float) $amount, 2 );

        $wpdb->query( 'START TRANSACTION' );

        // Trava a linha e verifica apenas saldo liberado.
        $query = $wpdb->prepare(
            "SELECT SUM(
                CASE 
                    WHEN type = 'credito' THEN amount 
                    WHEN type = 'debito' THEN -amount 
                    ELSE 0 
                END
            ) FROM {$table} WHERE user_id = %d AND status = 'cleared' FOR UPDATE",
            $user_id
        );
        $current_balance = round( (float) $wpdb->get_var( $query ), 2 );

        if ( $current_balance < $amount ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'     => $user_id,
                'order_id'    => $order_id,
                'type'        => 'debito',
                'amount'      => $amount,
                'description' => sanitize_text_field( $description ),
                'status'      => 'cleared' // Débitos são sempre cleared
            ],
            [ '%d', '%d', '%s', '%f', '%s', '%s' ]
        );

        if ( $inserted ) {
            $wpdb->query( 'COMMIT' );
            return self::get_balance( $user_id );
        }

        $wpdb->query( 'ROLLBACK' );
        return false;
    }

    /**
     * Atualiza o status de uma transação pendente.
     */
    /**
     * Atualiza o status de uma transação pendente e opcionalmente sua descrição.
     */
    public static function update_transaction_status_by_order( $order_id, $new_status, $new_description = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';
        
        if ( ! in_array( $new_status, ['cleared', 'cancelled'] ) ) return false;

        $update_data   = [ 'status' => $new_status ];
        $update_format = [ '%s' ];

        // Se enviou uma descrição nova, atualiza o texto no banco
        if ( ! empty( $new_description ) ) {
            $update_data['description'] = sanitize_text_field( $new_description );
            $update_format[]            = '%s';
        }

        $updated = $wpdb->update( 
            $table, 
            $update_data, 
            [ 'order_id' => $order_id, 'type' => 'credito', 'status' => 'pending' ],
            $update_format, 
            [ '%d', '%s', '%s' ]
        );

        return $updated !== false;
    }

    /**
     * Reverte um fundo (Estorno) forçando o débito.
     * Usado quando o pedido é reembolsado, ignorando a trava de saldo atual.
     */
    public static function revert_funds( $user_id, $amount, $description, $order_id = 0 ) {
        if ( ! $user_id || $amount <= 0 ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';
        $amount = round( (float) $amount, 2 );

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'     => $user_id,
                'order_id'    => $order_id,
                'type'        => 'debito',
                'amount'      => $amount,
                'description' => sanitize_text_field( $description ),
                'status'      => 'cleared' // Estornos são definitivos
            ],
            [ '%d', '%d', '%s', '%f', '%s', '%s' ]
        );

        return $inserted ? self::get_balance( $user_id ) : false;
    }

    /**
     * Recupera o histórico de transações via SQL nativo mapeado para o frontend.
     */
    public static function get_history( $user_id, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id,
            $limit
        );

        $results = $wpdb->get_results( $query, ARRAY_A );
        $history = [];
        
        if ( $results ) {
            foreach ( $results as $row ) {
                $history[] = [
                    'id'        => $row['id'],
                    'data'      => $row['created_at'],
                    'tipo'      => $row['type'],
                    'valor'     => (float) $row['amount'],
                    'descricao' => $row['description'],
                    'pedido_id' => $row['order_id'],
                    'status'    => $row['status'] 
                ];
            }
        }

        return $history;
    }
}