<?php
/**
 * LancamentoBancarioHelper
 *
 * Realiza o lançamento automático do valor de um pedido
 * na conta bancária configurada para aceitar o meio de pagamento usado.
 *
 * Regra de negócio:
 *   - O lançamento ocorre quando o pedido atinge status PAID/SUCCESSFUL/APPROVED
 *   - A CONTA BANCÁRIA é o elemento central (define quais meios aceita)
 *   - O valor lançado é o VALOR LÍQUIDO = valor bruto - taxa (% + fixa)
 *   - A movimentação registra: valor bruto, taxa descontada e valor líquido
 *
 * Compatível com MySQL 5.7 / MariaDB — Choppon ERP v1.2.0
 */
class LancamentoBancarioHelper
{
    /**
     * Mapa de normalização: valor do campo method → chave interna
     */
    private static $metodo_map = [
        'pix'         => 'pix',
        'PIX'         => 'pix',
        'credit'      => 'credit',
        'CREDIT'      => 'credit',
        'credit_card' => 'credit',
        'CREDIT_CARD' => 'credit',
        'credito'     => 'credit',
        'debit'       => 'debit',
        'DEBIT'       => 'debit',
        'debit_card'  => 'debit',
        'DEBIT_CARD'  => 'debit',
        'debito'      => 'debit',
        'cash'        => 'cash',
        'CASH'        => 'cash',
        'dinheiro'    => 'cash',
        'sumup'       => 'sumup',
        'SUMUP'       => 'sumup',
        'card'        => 'sumup',
        'CARD'        => 'sumup',
    ];

    /**
     * Mapa de tipo de pagamento → tipo em formas_pagamento
     */
    private static $tipo_map = [
        'pix'    => 'pix',
        'credit' => 'credito',
        'debit'  => 'debito',
        'cash'   => null,
        'sumup'  => null,
    ];

    /**
     * Lança o valor LÍQUIDO do pedido (bruto - taxa) na conta bancária correta.
     * Disparado quando checkout_status = PAID/SUCCESSFUL/APPROVED.
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

        // ── 3. Verificar se o pedido está apto (PAID/SUCCESSFUL/APPROVED) ─
        $status_ok = in_array(strtoupper($order['checkout_status'] ?? ''), ['SUCCESSFUL', 'PAID', 'APPROVED', 'COMPLETED']);
        if (!$status_ok) {
            return self::resp(false, 'Pedido não está com status de pagamento aprovado.');
        }

        $valor_bruto = floatval($order['valor'] ?? $order['total'] ?? 0);
        if ($valor_bruto <= 0) {
            return self::resp(false, 'Valor do pedido é zero ou inválido.');
        }

        $estabelecimento_id = intval($order['estabelecimento_id'] ?? 0);
        if (!$estabelecimento_id) {
            return self::resp(false, 'Pedido sem estabelecimento vinculado.');
        }

        // ── 4. Normalizar o meio de pagamento do pedido ───────────────────
        $metodo_raw   = strtolower(trim($order['method'] ?? $order['payment_method'] ?? $order['metodo'] ?? ''));
        $metodo_chave = self::$metodo_map[$metodo_raw] ?? self::$metodo_map[strtoupper($metodo_raw)] ?? $metodo_raw;

        // ── 5. Buscar taxa do meio de pagamento ───────────────────────────
        $taxa_percentual = 0.0;
        $taxa_fixa       = 0.0;
        $forma_id        = intval($order['forma_pagamento_id'] ?? 0);

        // 5a. Buscar taxa via forma_pagamento_id (mais preciso)
        if ($forma_id) {
            try {
                $stmt = $conn->prepare("
                    SELECT taxa_percentual, taxa_fixa, conta_bancaria_id
                    FROM formas_pagamento
                    WHERE id = ? AND estabelecimento_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$forma_id, $estabelecimento_id]);
                $fp = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fp) {
                    $taxa_percentual = floatval($fp['taxa_percentual'] ?? 0);
                    $taxa_fixa       = floatval($fp['taxa_fixa'] ?? 0);
                }
            } catch (Exception $e) {}
        }

        // 5b. Buscar taxa via tipo do meio de pagamento (fallback)
        if ($taxa_percentual == 0 && $taxa_fixa == 0 && $metodo_chave) {
            $tipo_fp = self::$tipo_map[$metodo_chave] ?? null;
            if ($tipo_fp) {
                try {
                    $stmt = $conn->prepare("
                        SELECT taxa_percentual, taxa_fixa
                        FROM formas_pagamento
                        WHERE estabelecimento_id = ? AND tipo = ? AND ativo = 1
                        ORDER BY id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$estabelecimento_id, $tipo_fp]);
                    $fp2 = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($fp2) {
                        $taxa_percentual = floatval($fp2['taxa_percentual'] ?? 0);
                        $taxa_fixa       = floatval($fp2['taxa_fixa'] ?? 0);
                    }
                } catch (Exception $e) {}
            }
        }

        // ── 6. Calcular valor líquido ─────────────────────────────────────
        $taxa_valor  = round(($valor_bruto * $taxa_percentual / 100) + $taxa_fixa, 2);
        $valor_liquido = round($valor_bruto - $taxa_valor, 2);
        if ($valor_liquido <= 0) {
            $valor_liquido = $valor_bruto; // segurança: nunca lançar zero
        }

        // ── 7. Buscar conta bancária ──────────────────────────────────────
        $conta_bancaria_id = null;

        // 7a. Conta via meios_pagamento_aceitos (regra central)
        $has_col_meios = false;
        try {
            $conn->query("SELECT meios_pagamento_aceitos FROM contas_bancarias LIMIT 1");
            $has_col_meios = true;
        } catch (Exception $e) {}

        if ($has_col_meios && $metodo_chave) {
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

        // 7b. Conta via forma_pagamento_id (legado)
        if (!$conta_bancaria_id && $forma_id) {
            try {
                $stmt = $conn->prepare("SELECT conta_bancaria_id FROM formas_pagamento WHERE id = ? AND estabelecimento_id = ? LIMIT 1");
                $stmt->execute([$forma_id, $estabelecimento_id]);
                $fp_row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fp_row && !empty($fp_row['conta_bancaria_id'])) {
                    $conta_bancaria_id = intval($fp_row['conta_bancaria_id']);
                }
            } catch (Exception $e) {}
        }

        // 7c. Primeira conta ativa do estabelecimento (fallback final)
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

        // ── 8. Montar descrição ───────────────────────────────────────────
        $bebida_nome  = $order['bebida_nome'] ?? '';
        $metodo_label = strtoupper($metodo_raw ?: 'PAGAMENTO');
        $descricao    = 'Venda #' . $order['id'];
        if ($bebida_nome) {
            $descricao .= ' — ' . $bebida_nome;
        }
        $descricao .= ' (' . $metodo_label . ')';
        if ($taxa_valor > 0) {
            $descricao .= ' | Taxa: R$ ' . number_format($taxa_valor, 2, ',', '.');
        }

        // ── 9. Verificar se a coluna referencia_pedido_id existe ─────────
        $col_ref_exists = false;
        try {
            $conn->query("SELECT referencia_pedido_id FROM movimentacoes_bancarias LIMIT 1");
            $col_ref_exists = true;
        } catch (Exception $e) {}

        // ── 10. Inserir movimentação e atualizar saldo ────────────────────
        $conn->beginTransaction();
        try {
            if ($col_ref_exists) {
                $stmt = $conn->prepare("
                    INSERT INTO movimentacoes_bancarias
                        (conta_bancaria_id, estabelecimento_id, tipo, descricao, valor,
                         data_movimentacao, categoria, centro_custo, classificacao,
                         created_by, referencia_pedido_id)
                    VALUES
                        (?, ?, 'entrada', ?, ?, CURDATE(),
                         'Receita de Vendas', 'Operacional', 'Receita Operacional',
                         ?, ?)
                ");
                $stmt->execute([
                    $conta_bancaria_id,
                    $estabelecimento_id,
                    $descricao,
                    $valor_liquido,
                    $userId ?: null,
                    $order['id'],
                ]);
            } else {
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
                    $valor_liquido,
                    $userId ?: null,
                ]);
            }
            $lancamento_id = intval($conn->lastInsertId());

            // Atualizar saldo_atual com o valor LÍQUIDO
            $conn->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id = ?")
                 ->execute([$valor_liquido, $conta_bancaria_id]);

            // Registrar lancamento_bancario_id e taxa_aplicada no pedido (se colunas existirem)
            if ($col_lanc_exists) {
                // Verificar se a coluna taxa_aplicada existe na tabela order
                $col_taxa_exists = false;
                try {
                    $conn->query("SELECT taxa_aplicada FROM `order` LIMIT 1");
                    $col_taxa_exists = true;
                } catch (Exception $e) {}

                if ($col_taxa_exists) {
                    $conn->prepare("UPDATE `order` SET lancamento_bancario_id = ?, taxa_aplicada = ? WHERE id = ?")
                         ->execute([$lancamento_id, $taxa_valor, $order['id']]);
                } else {
                    $conn->prepare("UPDATE `order` SET lancamento_bancario_id = ? WHERE id = ?")
                         ->execute([$lancamento_id, $order['id']]);
                }
            }

            $conn->commit();

            // Log
            $log_msg = sprintf(
                "[%s] Lançamento #%d | Pedido #%d | Bruto: R$ %.2f | Taxa: R$ %.2f (%.2f%% + R$ %.2f fixo) | Líquido: R$ %.2f | Conta: %d | Método: %s\n",
                date('Y-m-d H:i:s'),
                $lancamento_id,
                $order['id'],
                $valor_bruto,
                $taxa_valor,
                $taxa_percentual,
                $taxa_fixa,
                $valor_liquido,
                $conta_bancaria_id,
                $metodo_label
            );
            @file_put_contents(
                dirname(__DIR__) . '/logs/lancamento_bancario.log',
                $log_msg,
                FILE_APPEND
            );

            return self::resp(true, sprintf(
                'Lançamento realizado. Bruto: R$ %.2f | Taxa: R$ %.2f | Líquido: R$ %.2f',
                $valor_bruto, $taxa_valor, $valor_liquido
            ), $lancamento_id);

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
