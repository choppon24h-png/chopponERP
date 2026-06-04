<?php
/**
 * LancamentoBancarioHelper
 *
 * Realiza o lançamento automático do valor de um pedido
 * na conta bancária configurada para aceitar o meio de pagamento usado.
 *
 * Regra de negócio:
 *   - A CONTA BANCÁRIA é o elemento central
 *   - Cada conta define quais meios de pagamento são aceitos (meios_pagamento_aceitos)
 *   - Ao finalizar um pedido (SUCCESSFUL + FINISHED), busca a conta que aceita
 *     o meio de pagamento do pedido e lança a entrada
 *
 * Compatível com MySQL 5.7 / MariaDB — Choppon ERP v1.1.0
 */
class LancamentoBancarioHelper
{
    /**
     * Mapa de normalização: valor do campo payment_method → chave interna
     */
    private static $metodo_map = [
        // PIX
        'pix'        => 'pix',
        'PIX'        => 'pix',
        // Crédito
        'credit'     => 'credit',
        'CREDIT'     => 'credit',
        'credit_card'=> 'credit',
        'CREDIT_CARD'=> 'credit',
        'credito'    => 'credit',
        // Débito
        'debit'      => 'debit',
        'DEBIT'      => 'debit',
        'debit_card' => 'debit',
        'DEBIT_CARD' => 'debit',
        'debito'     => 'debit',
        // Dinheiro
        'cash'       => 'cash',
        'CASH'       => 'cash',
        'dinheiro'   => 'cash',
        // SumUp
        'sumup'      => 'sumup',
        'SUMUP'      => 'sumup',
        'card'       => 'sumup',
        'CARD'       => 'sumup',
    ];

    /**
     * Lança o valor do pedido na conta bancária correta.
     *
     * @param PDO    $conn      Conexão PDO
     * @param array  $order     Linha completa da tabela `order` (com bebida_nome se disponível)
     * @param int    $userId    ID do usuário que disparou a ação (0 para sistema)
     * @return array ['success'=>bool, 'message'=>string, 'lancamento_id'=>int|null]
     */
    public static function lancarPedido(PDO $conn, array $order, int $userId = 0): array
    {
        // ── 1. Verificar se as tabelas necessárias existem ────────────────
        try {
            $conn->query("SELECT id FROM contas_bancarias LIMIT 1");
        } catch (Exception $e) {
            return self::resp(false, 'Tabela contas_bancarias não existe. Execute a migração.');
        }

        // ── 2. Verificar idempotência (já foi lançado?) ───────────────────
        $col_lanc_exists = false;
        try {
            $conn->query("SELECT lancamento_bancario_id FROM `order` LIMIT 1");
            $col_lanc_exists = true;
        } catch (Exception $e) {}

        if ($col_lanc_exists && !empty($order['lancamento_bancario_id'])) {
            return self::resp(true, 'Lançamento já realizado anteriormente.', (int)$order['lancamento_bancario_id']);
        }

        // ── 3. Verificar se o pedido está apto ───────────────────────────
        $status_ok = in_array(strtoupper($order['checkout_status'] ?? ''), ['SUCCESSFUL', 'PAID', 'APPROVED']);
        if (!$status_ok) {
            return self::resp(false, 'Pedido não está com status de pagamento aprovado.');
        }
        if (strtoupper($order['status_liberacao'] ?? '') !== 'FINISHED') {
            return self::resp(false, 'Pedido ainda não foi finalizado (FINISHED).');
        }

        $valor = floatval($order['valor'] ?? $order['total'] ?? 0);
        if ($valor <= 0) {
            return self::resp(false, 'Valor do pedido é zero ou inválido.');
        }

        $estabelecimento_id = intval($order['estabelecimento_id'] ?? 0);
        if (!$estabelecimento_id) {
            return self::resp(false, 'Pedido sem estabelecimento vinculado.');
        }

        // ── 4. Normalizar o meio de pagamento do pedido ───────────────────
        $metodo_raw   = strtolower(trim($order['payment_method'] ?? $order['metodo'] ?? ''));
        $metodo_chave = self::$metodo_map[$metodo_raw] ?? self::$metodo_map[strtoupper($metodo_raw)] ?? $metodo_raw;

        // ── 5. Buscar conta bancária pela nova regra ──────────────────────
        // Regra: buscar conta que tem meios_pagamento_aceitos contendo o meio do pedido
        $conta_bancaria_id = null;

        // 5a. Buscar via meios_pagamento_aceitos (nova regra central)
        $has_col_meios = false;
        try {
            $conn->query("SELECT meios_pagamento_aceitos FROM contas_bancarias LIMIT 1");
            $has_col_meios = true;
        } catch (Exception $e) {}

        if ($has_col_meios && $metodo_chave) {
            // Busca conta ativa que aceita este meio de pagamento
            $stmt = $conn->prepare("
                SELECT id
                FROM contas_bancarias
                WHERE estabelecimento_id = ?
                  AND ativa = 1
                  AND meios_pagamento_aceitos IS NOT NULL
                  AND meios_pagamento_aceitos != ''
                  AND FIND_IN_SET(?, meios_pagamento_aceitos)
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$estabelecimento_id, $metodo_chave]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $conta_bancaria_id = intval($row['id']);
            }
        }

        // 5b. Fallback legado: conta vinculada via forma_pagamento_id
        if (!$conta_bancaria_id) {
            $fp_id = intval($order['forma_pagamento_id'] ?? 0);
            if ($fp_id) {
                try {
                    $stmt = $conn->prepare("SELECT conta_bancaria_id FROM formas_pagamento WHERE id = ? AND estabelecimento_id = ? LIMIT 1");
                    $stmt->execute([$fp_id, $estabelecimento_id]);
                    $fp_row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($fp_row && !empty($fp_row['conta_bancaria_id'])) {
                        $conta_bancaria_id = intval($fp_row['conta_bancaria_id']);
                    }
                } catch (Exception $e) {}
            }
        }

        // 5c. Fallback final: primeira conta ativa do estabelecimento
        if (!$conta_bancaria_id) {
            try {
                $stmt = $conn->prepare("SELECT id FROM contas_bancarias WHERE estabelecimento_id = ? AND ativa = 1 ORDER BY id ASC LIMIT 1");
                $stmt->execute([$estabelecimento_id]);
                $cb_row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cb_row) {
                    $conta_bancaria_id = intval($cb_row['id']);
                }
            } catch (Exception $e) {}
        }

        if (!$conta_bancaria_id) {
            return self::resp(false, 'Nenhuma conta bancária encontrada para este estabelecimento.');
        }

        // ── 6. Montar descrição ───────────────────────────────────────────
        $bebida_nome  = $order['bebida_nome'] ?? '';
        $descricao    = 'Venda #' . $order['id'];
        if ($bebida_nome) {
            $descricao .= ' — ' . $bebida_nome;
        }
        $metodo_label = strtoupper($metodo_raw ?: 'PAGAMENTO');
        $descricao   .= ' (' . $metodo_label . ')';

        // ── 7. Inserir movimentação e atualizar saldo ─────────────────────
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes_bancarias
                    (conta_bancaria_id, estabelecimento_id, tipo, descricao, valor,
                     data_movimentacao, categoria, centro_custo, classificacao, created_by)
                VALUES
                    (?, ?, 'entrada', ?, ?, CURDATE(),
                     'Receita de Vendas', 'Operacional', 'Receita Operacional', ?)
            ");
            $stmt->execute([
                $conta_bancaria_id,
                $estabelecimento_id,
                $descricao,
                $valor,
                $userId ?: null,
            ]);
            $lancamento_id = intval($conn->lastInsertId());

            // Atualizar saldo_atual
            $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id = ?")
                 ->execute([$valor, $conta_bancaria_id]);

            // Registrar lancamento_bancario_id no pedido (se coluna existir)
            if ($col_lanc_exists) {
                $conn->prepare("UPDATE `order` SET lancamento_bancario_id = ? WHERE id = ?")
                     ->execute([$lancamento_id, $order['id']]);
            }

            $conn->commit();
            return self::resp(true, 'Lançamento realizado com sucesso.', $lancamento_id);

        } catch (Exception $e) {
            $conn->rollBack();
            return self::resp(false, 'Erro ao lançar: ' . $e->getMessage());
        }
    }

    private static function resp(bool $success, string $message, ?int $lancamento_id = null): array
    {
        return ['success' => $success, 'message' => $message, 'lancamento_id' => $lancamento_id];
    }
}
