<?php
/**
 * PedidoEstoqueManager.php
 * Gerencia pedidos de saída de estoque para estabelecimentos ou clientes finais.
 *
 * Fluxo de status:
 *   aguardando → visualizado → faturado (baixa no estoque)
 *                           → cancelado
 */
class PedidoEstoqueManager
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->garantirTabelas();
    }

    // ── Garantir tabelas ──────────────────────────────────────────────────────

    private function garantirTabelas(): void
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS `estoque_pedidos` (
              `id`                INT(11) NOT NULL AUTO_INCREMENT,
              `numero_pedido`     VARCHAR(20) NOT NULL,
              `tipo_destinatario` ENUM('estabelecimento','cliente_final') NOT NULL DEFAULT 'estabelecimento',
              `estabelecimento_id` BIGINT UNSIGNED NULL,
              `cliente_nome`      VARCHAR(255) NULL,
              `cliente_cpf_cnpj`  VARCHAR(20)  NULL,
              `cliente_email`     VARCHAR(255) NULL,
              `cliente_telefone`  VARCHAR(20)  NULL,
              `entrega`           TINYINT(1)   NOT NULL DEFAULT 0,
              `entrega_cep`       VARCHAR(10)  NULL,
              `entrega_logradouro` VARCHAR(255) NULL,
              `entrega_numero`    VARCHAR(20)  NULL,
              `entrega_complemento` VARCHAR(100) NULL,
              `entrega_bairro`    VARCHAR(100) NULL,
              `entrega_cidade`    VARCHAR(100) NULL,
              `entrega_estado`    VARCHAR(2)   NULL,
              `entrega_taxa`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `subtotal`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `desconto`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `status`            ENUM('aguardando','visualizado','faturado','cancelado') NOT NULL DEFAULT 'aguardando',
              `data_faturamento`  TIMESTAMP NULL,
              `observacoes`       TEXT NULL,
              `criado_por`        INT(11) NOT NULL,
              `faturado_por`      INT(11) NULL,
              `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_numero_pedido` (`numero_pedido`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS `estoque_pedido_itens` (
              `id`              INT(11) NOT NULL AUTO_INCREMENT,
              `pedido_id`       INT(11) NOT NULL,
              `produto_id`      INT(11) NOT NULL,
              `quantidade`      INT(11) NOT NULL DEFAULT 1,
              `preco_unitario`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `observacoes`     VARCHAR(255) NULL,
              `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_pedido`  (`pedido_id`),
              INDEX `idx_produto` (`produto_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS `estoque_pedidos_sequencia` (
              `id`         INT(11) NOT NULL AUTO_INCREMENT,
              `ultimo_num` INT(11) NOT NULL DEFAULT 0,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->conn->exec("INSERT IGNORE INTO `estoque_pedidos_sequencia` (`id`, `ultimo_num`) VALUES (1, 0)");
    }

    // ── Numeração ─────────────────────────────────────────────────────────────

    private function gerarNumeroPedido(): string
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->query("SELECT ultimo_num FROM estoque_pedidos_sequencia WHERE id = 1 FOR UPDATE");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $novo = (int)$row['ultimo_num'] + 1;
            $this->conn->prepare("UPDATE estoque_pedidos_sequencia SET ultimo_num = ? WHERE id = 1")->execute([$novo]);
            $this->conn->commit();
            return 'PED-' . str_pad($novo, 4, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ── Criar pedido ──────────────────────────────────────────────────────────

    /**
     * Cria um novo pedido com seus itens.
     *
     * @param array $dados  Campos do formulário ($_POST)
     * @param int   $user_id
     * @return array ['success'=>bool, 'message'=>string, 'pedido_id'=>int]
     */
    public function criar(array $dados, int $user_id): array
    {
        // Validações básicas
        if (empty($dados['tipo_destinatario'])) {
            return ['success' => false, 'message' => 'Tipo de destinatário obrigatório.'];
        }
        if ($dados['tipo_destinatario'] === 'estabelecimento' && empty($dados['estabelecimento_id'])) {
            return ['success' => false, 'message' => 'Selecione o estabelecimento.'];
        }
        if ($dados['tipo_destinatario'] === 'cliente_final' && empty(trim($dados['cliente_nome'] ?? ''))) {
            return ['success' => false, 'message' => 'Nome do cliente é obrigatório.'];
        }
        if (empty($dados['itens']) || !is_array($dados['itens'])) {
            return ['success' => false, 'message' => 'Adicione pelo menos um produto ao pedido.'];
        }

        // Calcular totais
        $subtotal = 0;
        foreach ($dados['itens'] as $item) {
            $qty   = (int)($item['quantidade'] ?? 0);
            $preco = (float)str_replace(['.', ','], ['', '.'], $item['preco_unitario'] ?? '0');
            if ($qty > 0) {
                $subtotal += $qty * $preco;
            }
        }

        $entrega      = !empty($dados['entrega']) ? 1 : 0;
        $entrega_taxa = $entrega ? (float)str_replace(['.', ','], ['', '.'], $dados['entrega_taxa'] ?? '0') : 0;
        $desconto     = (float)str_replace(['.', ','], ['', '.'], $dados['desconto'] ?? '0');
        $total        = $subtotal + $entrega_taxa - $desconto;

        $numero = $this->gerarNumeroPedido();

        $stmt = $this->conn->prepare("
            INSERT INTO estoque_pedidos
                (numero_pedido, tipo_destinatario, estabelecimento_id,
                 cliente_nome, cliente_cpf_cnpj, cliente_email, cliente_telefone,
                 entrega, entrega_cep, entrega_logradouro, entrega_numero,
                 entrega_complemento, entrega_bairro, entrega_cidade, entrega_estado, entrega_taxa,
                 subtotal, desconto, total, observacoes, criado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $numero,
            $dados['tipo_destinatario'],
            $dados['tipo_destinatario'] === 'estabelecimento' ? (int)$dados['estabelecimento_id'] : null,
            trim($dados['cliente_nome']      ?? '') ?: null,
            trim($dados['cliente_cpf_cnpj']  ?? '') ?: null,
            trim($dados['cliente_email']     ?? '') ?: null,
            trim($dados['cliente_telefone']  ?? '') ?: null,
            $entrega,
            trim($dados['entrega_cep']          ?? '') ?: null,
            trim($dados['entrega_logradouro']    ?? '') ?: null,
            trim($dados['entrega_numero']        ?? '') ?: null,
            trim($dados['entrega_complemento']   ?? '') ?: null,
            trim($dados['entrega_bairro']        ?? '') ?: null,
            trim($dados['entrega_cidade']        ?? '') ?: null,
            trim($dados['entrega_estado']        ?? '') ?: null,
            $entrega_taxa,
            $subtotal,
            $desconto,
            $total,
            trim($dados['observacoes'] ?? '') ?: null,
            $user_id,
        ]);

        $pedido_id = (int)$this->conn->lastInsertId();

        // Inserir itens
        $stmt_item = $this->conn->prepare("
            INSERT INTO estoque_pedido_itens (pedido_id, produto_id, quantidade, preco_unitario, subtotal, observacoes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($dados['itens'] as $item) {
            $qty   = (int)($item['quantidade'] ?? 0);
            $preco = (float)str_replace(['.', ','], ['', '.'], $item['preco_unitario'] ?? '0');
            if ($qty > 0 && !empty($item['produto_id'])) {
                $stmt_item->execute([
                    $pedido_id,
                    (int)$item['produto_id'],
                    $qty,
                    $preco,
                    $qty * $preco,
                    trim($item['obs'] ?? '') ?: null,
                ]);
            }
        }

        return [
            'success'   => true,
            'message'   => "Pedido {$numero} criado com sucesso!",
            'pedido_id' => $pedido_id,
            'numero'    => $numero,
        ];
    }

    // ── Faturar pedido (baixa no estoque) ─────────────────────────────────────

    /**
     * Fatura o pedido: muda status para 'faturado' e registra saída no estoque.
     */
    public function faturar(int $pedido_id, int $user_id): array
    {
        $pedido = $this->buscarPorId($pedido_id);
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido não encontrado.'];
        }
        if ($pedido['status'] === 'faturado') {
            return ['success' => false, 'message' => 'Pedido já foi faturado.'];
        }
        if ($pedido['status'] === 'cancelado') {
            return ['success' => false, 'message' => 'Pedido cancelado não pode ser faturado.'];
        }

        $itens = $this->buscarItens($pedido_id);
        if (empty($itens)) {
            return ['success' => false, 'message' => 'Pedido sem itens.'];
        }

        // Verificar estoque disponível antes de debitar
        foreach ($itens as $item) {
            $stmt = $this->conn->prepare("SELECT nome, estoque_atual FROM estoque_produtos WHERE id = ?");
            $stmt->execute([$item['produto_id']]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
                return ['success' => false, 'message' => "Produto ID {$item['produto_id']} não encontrado."];
            }
            if ($prod['estoque_atual'] < $item['quantidade']) {
                return [
                    'success' => false,
                    'message' => "Estoque insuficiente para \"{$prod['nome']}\": disponível {$prod['estoque_atual']}, necessário {$item['quantidade']}.",
                ];
            }
        }

        // Iniciar transação
        $this->conn->beginTransaction();
        try {
            foreach ($itens as $item) {
                // Buscar dados atuais do produto
                $stmt = $this->conn->prepare("SELECT estoque_atual, preco_venda FROM estoque_produtos WHERE id = ? FOR UPDATE");
                $stmt->execute([$item['produto_id']]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                $estoque_anterior = (int)$prod['estoque_atual'];
                $estoque_novo     = $estoque_anterior - (int)$item['quantidade'];

                // Atualizar estoque
                $this->conn->prepare("UPDATE estoque_produtos SET estoque_atual = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$estoque_novo, $item['produto_id']]);

                // Registrar movimentação de saída
                $this->conn->prepare("
                    INSERT INTO estoque_movimentacoes
                        (produto_id, tipo, quantidade, quantidade_anterior, quantidade_nova,
                         custo_unitario, valor_total, motivo, observacoes, usuario_id)
                    VALUES (?, 'saida', ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $item['produto_id'],
                    $item['quantidade'],
                    $estoque_anterior,
                    $estoque_novo,
                    $item['preco_unitario'],
                    $item['subtotal'],
                    "Pedido {$pedido['numero_pedido']}",
                    "Faturamento do pedido {$pedido['numero_pedido']}",
                    $user_id,
                ]);
            }

            // Atualizar status do pedido
            $this->conn->prepare("
                UPDATE estoque_pedidos
                SET status = 'faturado', data_faturamento = NOW(), faturado_por = ?
                WHERE id = ?
            ")->execute([$user_id, $pedido_id]);

            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Erro ao faturar: ' . $e->getMessage()];
        }

        return ['success' => true, 'message' => "Pedido {$pedido['numero_pedido']} faturado! Estoque atualizado."];
    }

    // ── Marcar como visualizado ───────────────────────────────────────────────

    public function marcarVisualizado(int $pedido_id): array
    {
        $pedido = $this->buscarPorId($pedido_id);
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido não encontrado.'];
        }
        if ($pedido['status'] === 'aguardando') {
            $this->conn->prepare("UPDATE estoque_pedidos SET status = 'visualizado' WHERE id = ?")
                       ->execute([$pedido_id]);
        }
        return ['success' => true, 'pedido' => $this->buscarPorId($pedido_id)];
    }

    // ── Cancelar ──────────────────────────────────────────────────────────────

    public function cancelar(int $pedido_id): array
    {
        $pedido = $this->buscarPorId($pedido_id);
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido não encontrado.'];
        }
        if ($pedido['status'] === 'faturado') {
            return ['success' => false, 'message' => 'Pedido já faturado não pode ser cancelado.'];
        }
        $this->conn->prepare("UPDATE estoque_pedidos SET status = 'cancelado' WHERE id = ?")
                   ->execute([$pedido_id]);
        return ['success' => true, 'message' => "Pedido {$pedido['numero_pedido']} cancelado."];
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['status'])) {
            $where[]  = "p.status = ?";
            $params[] = $filtros['status'];
        }
        if (!empty($filtros['busca'])) {
            $where[]  = "(p.numero_pedido LIKE ? OR p.cliente_nome LIKE ? OR e.name LIKE ?)";
            $b        = '%' . $filtros['busca'] . '%';
            $params   = array_merge($params, [$b, $b, $b]);
        }
        if (!empty($filtros['data_inicio'])) {
            $where[]  = "DATE(p.created_at) >= ?";
            $params[] = $filtros['data_inicio'];
        }
        if (!empty($filtros['data_fim'])) {
            $where[]  = "DATE(p.created_at) <= ?";
            $params[] = $filtros['data_fim'];
        }

        $sql = "
            SELECT p.*,
                   e.name AS estabelecimento_nome,
                   u.name AS criado_por_nome
            FROM estoque_pedidos p
            LEFT JOIN estabelecimentos e ON e.id = p.estabelecimento_id
            LEFT JOIN users u ON u.id = p.criado_por
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.created_at DESC
            LIMIT 200
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT p.*,
                   e.name AS estabelecimento_nome,
                   e.document AS estabelecimento_document,
                   e.address AS estabelecimento_address,
                   e.phone AS estabelecimento_phone,
                   u.name AS criado_por_nome,
                   f.name AS faturado_por_nome
            FROM estoque_pedidos p
            LEFT JOIN estabelecimentos e ON e.id = p.estabelecimento_id
            LEFT JOIN users u ON u.id = p.criado_por
            LEFT JOIN users f ON f.id = p.faturado_por
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function buscarItens(int $pedido_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT i.*, p.nome AS produto_nome, p.codigo AS produto_codigo,
                   p.tamanho_litros, p.categoria
            FROM estoque_pedido_itens i
            INNER JOIN estoque_produtos p ON p.id = i.produto_id
            WHERE i.pedido_id = ?
            ORDER BY i.id ASC
        ");
        $stmt->execute([$pedido_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarEstabelecimentos(): array
    {
        $stmt = $this->conn->query("SELECT id, name, document, address FROM estabelecimentos WHERE status = 1 ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarProdutos(): array
    {
        $stmt = $this->conn->query("
            SELECT id, codigo, nome, preco_venda, estoque_atual, categoria, tamanho_litros
            FROM estoque_produtos
            WHERE ativo = 1
            ORDER BY nome ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function estatisticas(): array
    {
        $stmt = $this->conn->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'aguardando')  AS aguardando,
                SUM(status = 'visualizado') AS visualizado,
                SUM(status = 'faturado')    AS faturado,
                SUM(status = 'cancelado')   AS cancelado,
                COALESCE(SUM(CASE WHEN status = 'faturado' THEN total ELSE 0 END), 0) AS valor_faturado
            FROM estoque_pedidos
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
