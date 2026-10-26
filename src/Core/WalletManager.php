<?php
namespace WooDigitalWallet\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WalletManager {

    // Teto da coluna decimal(10,2). Acima disso o MySQL rejeita ou trunca o valor.
    const MAX_AMOUNT = 99999999.99;

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
     * Retorna o saldo PENDENTE (Shadow Ledger), já descontando reembolsos parciais pendentes.
     */
    public static function get_pending_balance( $user_id ) {
        if ( ! $user_id ) return 0.00;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $query = $wpdb->prepare(
            "SELECT SUM(
                CASE
                    WHEN type = 'credito' THEN amount
                    WHEN type = 'debito' THEN -amount
                    ELSE 0
                END
            ) FROM {$table} WHERE user_id = %d AND status = 'pending'",
            $user_id
        );

        $saldo = $wpdb->get_var( $query );
        return max( 0, round( (float) $saldo, 2 ) );
    }

    /**
     * Normaliza o valor. Retorna 0 para NaN, INF, negativo, zero ou acima do teto da coluna.
     */
    public static function normalize_amount( $amount ) {
        $amount = (float) $amount;
        if ( ! is_finite( $amount ) ) return 0.0;

        $amount = round( $amount, 2 );
        return ( $amount > 0 && $amount <= self::MAX_AMOUNT ) ? $amount : 0.0;
    }

    /**
     * Grava uma linha no ledger.
     * Com $idempotency_key, um evento repetido não grava de novo: o UNIQUE do banco barra.
     *
     * @return string|false 'inserted', 'duplicate' ou false em erro.
     */
    private static function insert_entry( $user_id, $type, $amount, $description, $order_id, $status, $idempotency_key = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $args = [
            (int) $user_id,
            (int) $order_id,
            $type,
            number_format( $amount, 2, '.', '' ),
            sanitize_text_field( $description ),
            $status,
        ];

        $key_placeholder = 'NULL';
        if ( null !== $idempotency_key ) {
            $key_placeholder = '%s';
            $args[]          = substr( (string) $idempotency_key, 0, 64 );
        }

        // ON DUPLICATE KEY UPDATE id = id: chave repetida não altera nada e devolve 0 linhas afetadas.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (user_id, order_id, type, amount, description, status, idempotency_key)
             VALUES (%d, %d, %s, %s, %s, %s, {$key_placeholder})
             ON DUPLICATE KEY UPDATE id = id",
            $args
        ) );

        if ( false === $result ) return false;
        return $result > 0 ? 'inserted' : 'duplicate';
    }

    /**
     * Adiciona fundos à carteira, aceitando provisionamento (pending).
     */
    public static function add_funds( $user_id, $amount, $description, $order_id = 0, $status = 'cleared', $idempotency_key = null ) {
        $amount = self::normalize_amount( $amount );
        if ( ! $user_id || $amount <= 0 ) return false;

        $status = in_array( $status, ['cleared', 'pending', 'cancelled'], true ) ? $status : 'cleared';

        $inserted = self::insert_entry( $user_id, 'credito', $amount, $description, $order_id, $status, $idempotency_key );

        return $inserted ? self::get_balance( $user_id ) : false;
    }

    /**
     * Deduz fundos da carteira (Apenas do saldo liberado).
     * Repetir a mesma $idempotency_key devolve sucesso sem debitar de novo.
     */
    public static function deduct_funds( $user_id, $amount, $description, $order_id = 0, $idempotency_key = null ) {
        $amount = self::normalize_amount( $amount );
        if ( ! $user_id || $amount <= 0 ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $wpdb->query( 'START TRANSACTION' );

        // Trava as linhas do usuário. Uma segunda requisição simultânea espera aqui até o COMMIT da primeira.
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

        // Depois da trava: se a mesma operação já foi gravada (retentativa), não é saldo insuficiente.
        if ( null !== $idempotency_key ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE idempotency_key = %s FOR UPDATE",
                substr( (string) $idempotency_key, 0, 64 )
            ) );
            if ( $existing ) {
                $wpdb->query( 'COMMIT' );
                return self::get_balance( $user_id );
            }
        }

        if ( $current_balance < $amount ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        // Débitos são sempre cleared
        $inserted = self::insert_entry( $user_id, 'debito', $amount, $description, $order_id, 'cleared', $idempotency_key );

        if ( $inserted ) {
            $wpdb->query( 'COMMIT' );
            return self::get_balance( $user_id );
        }

        $wpdb->query( 'ROLLBACK' );
        return false;
    }

    /**
     * Move as linhas de um pedido de um status para outro (ex.: pending -> cleared).
     * O UPDATE com WHERE status = $from_status é atômico: se duas requisições correrem juntas,
     * só uma afeta linhas. A descrição nova, se enviada, vale só para os créditos.
     *
     * @return int|false Quantidade de linhas alteradas.
     */
    public static function transition_order_entries( $order_id, $from_status, $to_status, $credit_description = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $allowed = [ 'pending', 'cleared', 'cancelled' ];
        if ( ! $order_id || ! in_array( $from_status, $allowed, true ) || ! in_array( $to_status, $allowed, true ) ) {
            return false;
        }

        $credit_data   = [ 'status' => $to_status ];
        $credit_format = [ '%s' ];

        // Se enviou uma descrição nova, atualiza o texto no banco
        if ( ! empty( $credit_description ) ) {
            $credit_data['description'] = sanitize_text_field( $credit_description );
            $credit_format[]            = '%s';
        }

        $credits = $wpdb->update(
            $table,
            $credit_data,
            [ 'order_id' => $order_id, 'type' => 'credito', 'status' => $from_status ],
            $credit_format,
            [ '%d', '%s', '%s' ]
        );

        // Reembolsos parciais lançados enquanto o crédito estava pendente acompanham o crédito.
        $debits = $wpdb->update(
            $table,
            [ 'status' => $to_status ],
            [ 'order_id' => $order_id, 'type' => 'debito', 'status' => $from_status ],
            [ '%s' ],
            [ '%d', '%s', '%s' ]
        );

        if ( false === $credits || false === $debits ) return false;
        return (int) $credits + (int) $debits;
    }

    /**
     * Reverte um fundo (Estorno) forçando o débito.
     * Usado quando o pedido é reembolsado, ignorando a trava de saldo atual (o saldo pode ficar negativo).
     */
    public static function revert_funds( $user_id, $amount, $description, $order_id = 0, $status = 'cleared', $idempotency_key = null ) {
        $amount = self::normalize_amount( $amount );
        if ( ! $user_id || $amount <= 0 ) return false;

        $status = in_array( $status, ['cleared', 'pending'], true ) ? $status : 'cleared';

        $inserted = self::insert_entry( $user_id, 'debito', $amount, $description, $order_id, $status, $idempotency_key );

        return $inserted ? self::get_balance( $user_id ) : false;
    }

    /**
     * Recupera o histórico de transações via SQL nativo mapeado para o frontend.
     */
    public static function get_history( $user_id, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcw_transactions';

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
            $user_id,
            max( 1, min( 200, (int) $limit ) )
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
