<?php
/**
 * LancamentoBancarioHelper
 * 
 * Realiza o lançamento automático do valor de um pedido
 * na conta bancária vinculada ao meio de pagamento,
 * quando o pedido atinge status SUCCESSFUL + FINISHED.
 *
 * Compatível com MySQL 5.7 / MariaDB
 * Choppon ERP — v1.0.0
 */
class LancamentoBancarioHelper
{
    /**
     * Lança o valor do pedido na conta bancária vinculada ao meio de pagamento.
     *
     * @param PDO    $conn      Conexão PDO
     * @param array  $order     Linha completa da tabela `order`
     * @param int    $userId    ID do usuário que disparou a ação (0 para sistema)
     * @return array ['success'=>bool, 'message'=>string, 'lancamento_id'=>int|null]
     */
    public static function lancarPedido(PDO $conn, array $order, int $userId = 0): array
    {
        // ── 1. Verificar se as tabelas necessárias existem ────────────────
        try {
            $conn->query("SELECT id FROM contas_bancarias LIMIT 1");
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Tabela contas_bancarias não existe. Execute a migração.', 'lancamento_id' => null];
        }

        // ── 2. Verificar se já foi lançado (idempotência) ─────────────────
        $col_check = false;
        try {
            $conn->query("SELECT lancamento_bancario_id FROM `order` LIMIT 1");
            $col_check = true;
        } catch (Exception $e) {}

        if ($col_check && !empty($order['lancamento_bancario_id'])) {
            return ['success' => true, 'message' => 'Lançamento já realizado anteriormente.', 'lancamento_id' => $order['lancamento_bancario_id']];
        }

        // ── 3. Verificar se o pedido está apto para lançamento ────────────
        if ($order['checkout_status'] !== 'SUCCESSFUL' && $order['checkout_status'] !== 'PAID' && $order['checkout_status'] !== 'APPROVED') {
            return ['success' => false, 'message' => 'Pedido não está com status de pagamento aprovado.', 'lancamento_id' => null];
        }
        if ($order['status_liberacao'] !== 'FINISHED') {
            return ['success' => false, 'message' => 'Pedido ainda não foi finalizado (FINISHED).', 'lancamento_id' => null];
        }

        $valor = floatval($order['valor'] ?? $order['total'] ?? 0);
        if ($valor <= 0) {
            return ['success' => false, 'message' => 'Valor do pedido é zero ou inválido.', 'lancamento_id' => null];
        }

        $estabelecimento_id = intval($order['estabelecimento_id'] ?? 0);
        if (!$estabelecimento_id) {
            return ['success' => false, 'message' => 'Pedido sem estabelecimento vinculado.', 'lancamento_id' => null];
        }

        // ── 4. Buscar conta bancária vinculada ao meio de pagamento ───────
        $conta_bancaria_id = null;
        $metodo_pedido     = strtolower(trim($order['payment_method'] ?? $order['metodo'] ?? ''));

        // Tentar via forma_pagamento_id do pedido
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

        // Fallback: buscar por tipo de pagamento (pix, credit, debit)
        if (!$conta_bancaria_id && $metodo_pedido) {
            $tipo_map = [
                'pix'    => 'pix',
                'credit' => 'credito',
                'debit'  => 'debito',
                'cash'   => 'dinheiro',
            ];
            $tipo_fp = $tipo_map[$metodo_pedido] ?? $metodo_pedido;
            try {
                $stmt = $conn->prepare("
                    SELECT conta_bancaria_id
                    FROM formas_pagamento
                    WHERE estabelecimento_id = ?
                      AND tipo = ?
                      AND ativo = 1
                      AND conta_bancaria_id IS NOT NULL
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $stmt->execute([$estabelecimento_id, $tipo_fp]);
                $fp_row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fp_row && !empty($fp_row['conta_bancaria_id'])) {
                    $conta_bancaria_id = intval($fp_row['conta_bancaria_id']);
                }
            } catch (Exception $e) {}
        }

        // Fallback final: primeira conta ativa do estabelecimento
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
            return ['success' => false, 'message' => 'Nenhuma conta bancária encontrada para este estabelecimento.', 'lancamento_id' => null];
        }

        // ── 5. Montar descrição do lançamento ─────────────────────────────
        $bebida_nome  = $order['bebida_nome'] ?? '';
        $checkout_id  = $order['checkout_id'] ?? $order['id'];
        $descricao    = 'Venda #' . $order['id'];
        if ($bebida_nome) {
            $descricao .= ' — ' . $bebida_nome;
        }
        $metodo_label = strtoupper($metodo_pedido ?: 'PAGAMENTO');
        $descricao   .= ' (' . $metodo_label . ')';

        // ── 6. Inserir movimentação e atualizar saldo ─────────────────────
        $conn->beginTransaction();
        try {
            // Inserir em movimentacoes_bancarias
            $stmt = $conn->prepare("
                INSERT INTO movimentacoes_bancarias
                    (conta_bancaria_id, estabelecimento_id, tipo, descricao, valor,
                     data_movimentacao, categoria, centro_custo, classificacao, created_by)
                VALUES
                    (?, ?, 'entrada', ?, ?, CURDATE(), 'Receita de Vendas', 'Operacional', 'Receita Operacional', ?)
            ");
            $stmt->execute([
                $conta_bancaria_id,
                $estabelecimento_id,
                $descricao,
                $valor,
                $userId ?: null,
            ]);
            $lancamento_id = intval($conn->lastInsertId());

            // Atualizar saldo_atual da conta bancária
            $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id = ?")
                 ->execute([$valor, $conta_bancaria_id]);

            // Atualizar order com lancamento_bancario_id (se coluna existir)
            if ($col_check) {
                $conn->prepare("UPDATE `order` SET lancamento_bancario_id = ? WHERE id = ?")
                     ->execute([$lancamento_id, $order['id']]);
            }

            $conn->commit();
            return ['success' => true, 'message' => 'Lançamento realizado com sucesso.', 'lancamento_id' => $lancamento_id];

        } catch (Exception $e) {
            $conn->rollBack();
            return ['success' => false, 'message' => 'Erro ao lançar: ' . $e->getMessage(), 'lancamento_id' => null];
        }
    }
}
