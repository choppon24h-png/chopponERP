<?php
/**
 * EstoqueManager - Gerenciador de Estoque de Barris
 * Responsável por toda lógica de negócio relacionada ao estoque
 * Suporte multi-estabelecimento: cada unidade vê apenas seu próprio estoque
 */

class EstoqueManager {
    private $conn;
    private $logger;
    private $estabelecimento_id; // null = Admin Geral (vê tudo)

    public function __construct($conn, $estabelecimento_id = null) {
        $this->conn = $conn;
        $this->estabelecimento_id = $estabelecimento_id ? (int)$estabelecimento_id : null;
    }

    /**
     * Retorna o WHERE clause de segurança por estabelecimento
     * para uso em queries diretas (não via listarProdutos)
     */
    private function whereEstab(string $alias = 'p'): string {
        if ($this->estabelecimento_id) {
            return " AND {$alias}.estabelecimento_id = {$this->estabelecimento_id}";
        }
        return '';
    }

    /**
     * Gerar código único para produto
     */
    public function gerarCodigo() {
        $stmt = $this->conn->query("SELECT MAX(id) as max_id FROM estoque_produtos");
        $result = $stmt->fetch();
        $next_id = ($result['max_id'] ?? 0) + 1;
        return 'BARRIL-' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Calcular markup
     */
    public function calcularMarkup($custo, $preco_venda) {
        if ($custo <= 0) return 0;
        return round((($preco_venda - $custo) / $custo) * 100, 2);
    }

    /**
     * Calcular preço de venda baseado no markup
     */
    public function calcularPrecoVenda($custo, $markup_percentual) {
        return round($custo * (1 + ($markup_percentual / 100)), 2);
    }

    /**
     * Calcular preço por 100ml
     */
    public function calcularPreco100ml($preco_venda, $tamanho_litros) {
        if ($tamanho_litros <= 0) return 0;
        $preco_ml = $preco_venda / ($tamanho_litros * 1000);
        return round($preco_ml * 100, 2);
    }

    /**
     * Criar produto
     */
    public function criarProduto($dados) {
        try {
            // Validar dados
            $this->validarProduto($dados);

            // Determinar estabelecimento_id: do manager (franqueado) ou do POST (admin)
            $estab_id = $this->estabelecimento_id
                ?? (isset($dados['estabelecimento_id']) ? (int)$dados['estabelecimento_id'] : null);
            if (!$estab_id) {
                throw new Exception('Estabelecimento não identificado para o produto.');
            }

            // Gerar código se não fornecido
            $codigo = $dados['codigo'] ?? $this->gerarCodigo();

            // Calcular markup se não for livre
            $markup_livre = isset($dados['markup_livre']) ? 1 : 0;
            $markup_percentual = null;

            if ($markup_livre && isset($dados['markup_percentual'])) {
                $markup_percentual = floatval($dados['markup_percentual']);
                $preco_venda = $this->calcularPrecoVenda(
                    floatval($dados['custo_compra']),
                    $markup_percentual
                );
            } else {
                $preco_venda = floatval($dados['preco_venda']);
                $markup_percentual = $this->calcularMarkup(
                    floatval($dados['custo_compra']),
                    $preco_venda
                );
            }

            // Calcular preço por 100ml
            $preco_100ml = $this->calcularPreco100ml(
                $preco_venda,
                floatval($dados['tamanho_litros'])
            );

            // Inserir produto
            $stmt = $this->conn->prepare("
                INSERT INTO estoque_produtos
                (estabelecimento_id, codigo, codigo_barras, nome, descricao, tamanho_litros, peso_kg,
                 fornecedor_id, estoque_minimo, estoque_maximo, estoque_atual,
                 custo_compra, preco_venda, markup_percentual, markup_livre,
                 preco_100ml, categoria, lote, data_validade, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");

            $stmt->execute([
                $estab_id,
                $codigo,
                $dados['codigo_barras'] ?? null,
                $dados['nome'],
                $dados['descricao'] ?? null,
                $dados['tamanho_litros'],
                $dados['peso_kg'] ?? null,
                $dados['fornecedor_id'] ?? null,
                $dados['estoque_minimo'] ?? 0,
                $dados['estoque_maximo'] ?? 0,
                0, // estoque inicial = 0
                $dados['custo_compra'],
                $preco_venda,
                $markup_percentual,
                $markup_livre,
                $preco_100ml,
                $dados['categoria'] ?? null,
                $dados['lote'] ?? null,
                $dados['data_validade'] ?? null
            ]);

            $produto_id = $this->conn->lastInsertId();

            // Registrar log
            $this->registrarLog('estoque_produtos', $produto_id, 'create', null, $dados);

            return [
                'success' => true,
                'produto_id' => $produto_id,
                'codigo' => $codigo,
                'message' => 'Produto cadastrado com sucesso!'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao cadastrar produto: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Atualizar produto
     */
    public function atualizarProduto($id, $dados) {
        try {
            // Buscar produto atual (já filtra por estabelecimento via buscarProdutoPorId)
            $produtoAtual = $this->buscarProdutoPorId($id);
            if (!$produtoAtual) {
                throw new Exception('Produto não encontrado');
            }

            // Validar dados
            $this->validarProduto($dados);

            // Calcular markup
            $markup_livre = isset($dados['markup_livre']) ? 1 : 0;
            $markup_percentual = null;

            if ($markup_livre && isset($dados['markup_percentual'])) {
                $markup_percentual = floatval($dados['markup_percentual']);
                $preco_venda = $this->calcularPrecoVenda(
                    floatval($dados['custo_compra']),
                    $markup_percentual
                );
            } else {
                $preco_venda = floatval($dados['preco_venda']);
                $markup_percentual = $this->calcularMarkup(
                    floatval($dados['custo_compra']),
                    $preco_venda
                );
            }

            $preco_100ml = $this->calcularPreco100ml(
                $preco_venda,
                floatval($dados['tamanho_litros'])
            );

            // Verificar se houve mudança de preço
            if (floatval($produtoAtual['custo_compra']) != floatval($dados['custo_compra'])) {
                $this->registrarHistoricoPreco(
                    $id,
                    $produtoAtual['custo_compra'],
                    $dados['custo_compra'],
                    $produtoAtual['preco_venda'],
                    $preco_venda,
                    $produtoAtual['markup_percentual'],
                    $markup_percentual
                );
            }

            // Atualizar produto (WHERE inclui estabelecimento_id para segurança)
            $extra_where = $this->estabelecimento_id
                ? " AND estabelecimento_id = {$this->estabelecimento_id}"
                : '';

            $stmt = $this->conn->prepare("
                UPDATE estoque_produtos
                SET codigo_barras = ?, nome = ?, descricao = ?, tamanho_litros = ?,
                    peso_kg = ?, fornecedor_id = ?, estoque_minimo = ?, estoque_maximo = ?,
                    custo_compra = ?, preco_venda = ?, markup_percentual = ?,
                    markup_livre = ?, preco_100ml = ?, categoria = ?, lote = ?,
                    data_validade = ?
                WHERE id = ? {$extra_where}
            ");

            $stmt->execute([
                $dados['codigo_barras'] ?? null,
                $dados['nome'],
                $dados['descricao'] ?? null,
                $dados['tamanho_litros'],
                $dados['peso_kg'] ?? null,
                $dados['fornecedor_id'] ?? null,
                $dados['estoque_minimo'] ?? 0,
                $dados['estoque_maximo'] ?? 0,
                $dados['custo_compra'],
                $preco_venda,
                $markup_percentual,
                $markup_livre,
                $preco_100ml,
                $dados['categoria'] ?? null,
                $dados['lote'] ?? null,
                $dados['data_validade'] ?? null,
                $id
            ]);

            // Registrar log
            $this->registrarLog('estoque_produtos', $id, 'update', $produtoAtual, $dados);

            return [
                'success' => true,
                'message' => 'Produto atualizado com sucesso!'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao atualizar produto: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Registrar movimentação de estoque
     */
    public function registrarMovimentacao($dados) {
        try {
            $produto = $this->buscarProdutoPorId($dados['produto_id']);
            if (!$produto) {
                throw new Exception('Produto não encontrado');
            }

            $quantidade_anterior = $produto['estoque_atual'];
            $tipo = $dados['tipo']; // entrada, saida, ajuste
            $quantidade = intval($dados['quantidade']);

            // Calcular nova quantidade
            if ($tipo == 'entrada' || $tipo == 'ajuste') {
                $quantidade_nova = $quantidade_anterior + $quantidade;
            } else {
                $quantidade_nova = $quantidade_anterior - $quantidade;
                if ($quantidade_nova < 0) {
                    throw new Exception('Estoque insuficiente');
                }
            }

            $custo_unitario = isset($dados['custo_unitario']) ? floatval($dados['custo_unitario']) : null;
            $valor_total = $custo_unitario ? ($custo_unitario * $quantidade) : null;

            // Verificar mudança de custo
            $markup_anterior = $produto['markup_percentual'];
            $markup_novo = $markup_anterior;

            if ($custo_unitario && $custo_unitario != $produto['custo_compra']) {
                $markup_novo = $this->calcularMarkup($custo_unitario, $produto['preco_venda']);
            }

            // Determinar estabelecimento da movimentação
            $estab_mov = $this->estabelecimento_id ?? ($produto['estabelecimento_id'] ?? null);

            // Inserir movimentação
            $stmt = $this->conn->prepare("
                INSERT INTO estoque_movimentacoes
                (estabelecimento_id, produto_id, tipo, quantidade, quantidade_anterior, quantidade_nova,
                 custo_unitario, custo_anterior, markup_anterior, markup_novo,
                 valor_total, lote, data_validade, fornecedor_id, nota_fiscal,
                 motivo, observacoes, usuario_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $estab_mov,
                $dados['produto_id'],
                $tipo,
                $quantidade,
                $quantidade_anterior,
                $quantidade_nova,
                $custo_unitario,
                $produto['custo_compra'],
                $markup_anterior,
                $markup_novo,
                $valor_total,
                $dados['lote'] ?? null,
                $dados['data_validade'] ?? null,
                $dados['fornecedor_id'] ?? null,
                $dados['nota_fiscal'] ?? null,
                $dados['motivo'] ?? null,
                $dados['observacoes'] ?? null,
                $_SESSION['user_id']
            ]);

            // Trigger irá atualizar o estoque automaticamente

            return [
                'success' => true,
                'quantidade_anterior' => $quantidade_anterior,
                'quantidade_nova' => $quantidade_nova,
                'markup_alterado' => ($markup_anterior != $markup_novo),
                'markup_anterior' => $markup_anterior,
                'markup_novo' => $markup_novo,
                'message' => 'Movimentação registrada com sucesso!'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao registrar movimentação: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Buscar produto por ID
     * Garante que o produto pertence ao estabelecimento do manager (segurança)
     */
    public function buscarProdutoPorId($id) {
        $extra = $this->whereEstab('p');
        $stmt = $this->conn->prepare("
            SELECT p.*, f.nome as fornecedor_nome,
                   e.name as estabelecimento_nome
            FROM estoque_produtos p
            LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
            LEFT JOIN estabelecimentos e ON p.estabelecimento_id = e.id
            WHERE p.id = ? {$extra}
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Listar produtos com filtro obrigatório por estabelecimento
     */
    public function listarProdutos($filtros = []) {
        $where = ["p.ativo = 1"];
        $params = [];

        // ── Filtro obrigatório por estabelecimento ──────────────
        if ($this->estabelecimento_id) {
            // Franqueado: vê APENAS os produtos do seu estabelecimento
            $where[] = "p.estabelecimento_id = ?";
            $params[] = $this->estabelecimento_id;
        } elseif (!empty($filtros['estabelecimento_id'])) {
            // Admin Geral com filtro manual
            $where[] = "p.estabelecimento_id = ?";
            $params[] = (int)$filtros['estabelecimento_id'];
        }
        // Admin Geral sem filtro: vê todos

        if (!empty($filtros['busca'])) {
            $where[] = "(p.nome LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)";
            $busca = '%' . $filtros['busca'] . '%';
            $params[] = $busca;
            $params[] = $busca;
            $params[] = $busca;
        }

        if (!empty($filtros['fornecedor_id'])) {
            $where[] = "p.fornecedor_id = ?";
            $params[] = $filtros['fornecedor_id'];
        }

        if (!empty($filtros['categoria'])) {
            $where[] = "p.categoria = ?";
            $params[] = $filtros['categoria'];
        }

        $sql = "
            SELECT p.*, f.nome as fornecedor_nome,
                   e.name as estabelecimento_nome
            FROM estoque_produtos p
            LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
            LEFT JOIN estabelecimentos e ON p.estabelecimento_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.nome
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Registrar histórico de preço
     */
    private function registrarHistoricoPreco($produto_id, $custo_anterior, $custo_novo,
                                            $preco_venda_anterior, $preco_venda_novo,
                                            $markup_anterior, $markup_novo) {
        $variacao = 0;
        if ($custo_anterior > 0) {
            $variacao = (($custo_novo - $custo_anterior) / $custo_anterior) * 100;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO estoque_historico_precos
            (produto_id, custo_anterior, custo_novo, variacao_percentual,
             preco_venda_anterior, preco_venda_novo, markup_anterior, markup_novo, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $produto_id,
            $custo_anterior,
            $custo_novo,
            round($variacao, 2),
            $preco_venda_anterior,
            $preco_venda_novo,
            $markup_anterior,
            $markup_novo,
            $_SESSION['user_id']
        ]);
    }

    /**
     * Registrar log de ação
     */
    private function registrarLog($tabela, $registro_id, $acao, $dados_anteriores, $dados_novos) {
        $stmt = $this->conn->prepare("
            INSERT INTO estoque_logs
            (tabela, registro_id, acao, dados_anteriores, dados_novos, usuario_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tabela,
            $registro_id,
            $acao,
            $dados_anteriores ? json_encode($dados_anteriores) : null,
            json_encode($dados_novos),
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * Validar dados do produto
     */
    private function validarProduto($dados) {
        $erros = [];

        if (empty($dados['nome'])) {
            $erros[] = 'Nome do produto é obrigatório';
        }

        if (empty($dados['tamanho_litros']) || floatval($dados['tamanho_litros']) <= 0) {
            $erros[] = 'Tamanho em litros deve ser maior que zero';
        }

        if (empty($dados['custo_compra']) || floatval($dados['custo_compra']) < 0) {
            $erros[] = 'Custo de compra inválido';
        }

        if (!empty($erros)) {
            throw new Exception(implode(', ', $erros));
        }
    }
}
