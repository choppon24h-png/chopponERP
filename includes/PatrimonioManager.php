<?php
/**
 * PatrimonioManager.php — Gerenciador do módulo de Inventário/Patrimônio
 *
 * Responsabilidades:
 *  - Geração automática de número PAT sequencial (PAT-0001, PAT-0002...)
 *  - Cadastro de patrimônio individual ou em lote (múltiplas unidades)
 *  - Upload de foto e NF
 *  - Listagem com filtros
 *  - Edição e exclusão
 *  - Registro de preventivas
 */
class PatrimonioManager
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
            CREATE TABLE IF NOT EXISTS `patrimonio` (
              `id`               INT(11) NOT NULL AUTO_INCREMENT,
              `numero_pat`       VARCHAR(20)  NOT NULL,
              `descricao`        VARCHAR(255) NOT NULL,
              `classificacao`    ENUM('imobilizado','ativo') NOT NULL DEFAULT 'ativo',
              `categoria`        VARCHAR(100) NULL,
              `marca`            VARCHAR(100) NULL,
              `modelo`           VARCHAR(100) NULL,
              `numero_serie`     VARCHAR(100) NULL,
              `valor_compra`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `data_compra`      DATE         NULL,
              `fornecedor`       VARCHAR(255) NULL,
              `numero_nf`        VARCHAR(50)  NULL,
              `arquivo_nf`       VARCHAR(500) NULL,
              `foto`             VARCHAR(500) NULL,
              `localizacao`      VARCHAR(255) NULL,
              `responsavel`      VARCHAR(255) NULL,
              `observacoes`      TEXT         NULL,
              `status`           ENUM('ativo','inativo','em_manutencao','baixado') NOT NULL DEFAULT 'ativo',
              `grupo_pat`        VARCHAR(50)  NULL,
              `quantidade_lote`  INT(11)      NOT NULL DEFAULT 1,
              `sequencia_lote`   INT(11)      NOT NULL DEFAULT 1,
              `tem_preventiva`   TINYINT(1)   NOT NULL DEFAULT 0,
              `periodicidade_preventiva` ENUM('mensal','bimestral','trimestral','semestral','anual') NULL,
              `proxima_preventiva` DATE       NULL,
              `ultima_preventiva`  DATE       NULL,
              `criado_por`       INT(11)      NULL,
              `atualizado_por`   INT(11)      NULL,
              `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_numero_pat` (`numero_pat`),
              INDEX `idx_classificacao` (`classificacao`),
              INDEX `idx_status`        (`status`),
              INDEX `idx_grupo_pat`     (`grupo_pat`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS `patrimonio_preventivas` (
              `id`              INT(11)      NOT NULL AUTO_INCREMENT,
              `patrimonio_id`   INT(11)      NOT NULL,
              `data_realizada`  DATE         NOT NULL,
              `descricao`       TEXT         NULL,
              `tecnico`         VARCHAR(255) NULL,
              `custo`           DECIMAL(10,2) NULL DEFAULT 0.00,
              `observacoes`     TEXT         NULL,
              `proxima_data`    DATE         NULL,
              `registrado_por`  INT(11)      NULL,
              `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_patrimonio` (`patrimonio_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS `patrimonio_sequencia` (
              `id`         INT(11) NOT NULL AUTO_INCREMENT,
              `ultimo_num` INT(11) NOT NULL DEFAULT 0,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Garantir registro inicial da sequência
        $this->conn->exec("INSERT IGNORE INTO `patrimonio_sequencia` (`id`, `ultimo_num`) VALUES (1, 0)");
    }

    // ── Geração de número PAT ─────────────────────────────────────────────────

    /**
     * Gera o próximo número PAT sequencial no formato PAT-XXXX
     * Usa transação para evitar duplicatas em concorrência
     */
    public function gerarNumeroPat(): string
    {
        $this->conn->beginTransaction();
        try {
            // Lock exclusivo no registro de sequência
            $stmt = $this->conn->query("SELECT ultimo_num FROM patrimonio_sequencia WHERE id = 1 FOR UPDATE");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $novo = (int)$row['ultimo_num'] + 1;

            $this->conn->prepare("UPDATE patrimonio_sequencia SET ultimo_num = ? WHERE id = 1")
                       ->execute([$novo]);

            $this->conn->commit();
            return 'PAT-' . str_pad($novo, 4, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Gera múltiplos números PAT em sequência (para lote)
     * @return string[] Array de números PAT
     */
    public function gerarNumerosPatLote(int $quantidade): array
    {
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->query("SELECT ultimo_num FROM patrimonio_sequencia WHERE id = 1 FOR UPDATE");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $base = (int)$row['ultimo_num'];

            $numeros = [];
            for ($i = 1; $i <= $quantidade; $i++) {
                $numeros[] = 'PAT-' . str_pad($base + $i, 4, '0', STR_PAD_LEFT);
            }

            $this->conn->prepare("UPDATE patrimonio_sequencia SET ultimo_num = ? WHERE id = 1")
                       ->execute([$base + $quantidade]);

            $this->conn->commit();
            return $numeros;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ── Upload de arquivos ────────────────────────────────────────────────────

    /**
     * Processa upload de foto do patrimônio
     * @return string|null Caminho relativo salvo ou null em caso de erro
     */
    public function uploadFoto(array $file): ?string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $ext_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $ext_permitidas)) {
            return null;
        }

        $upload_dir = dirname(__DIR__) . '/uploads/patrimonio/fotos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $nome_arquivo = 'pat_foto_' . uniqid() . '.' . $ext;
        $destino      = $upload_dir . $nome_arquivo;

        if (move_uploaded_file($file['tmp_name'], $destino)) {
            return 'uploads/patrimonio/fotos/' . $nome_arquivo;
        }

        return null;
    }

    /**
     * Processa upload de NF (PDF, JPG, PNG)
     * @return string|null Caminho relativo salvo ou null em caso de erro
     */
    public function uploadNF(array $file): ?string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $ext_permitidas = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $ext_permitidas)) {
            return null;
        }

        $upload_dir = dirname(__DIR__) . '/uploads/patrimonio/nf/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $nome_arquivo = 'pat_nf_' . uniqid() . '.' . $ext;
        $destino      = $upload_dir . $nome_arquivo;

        if (move_uploaded_file($file['tmp_name'], $destino)) {
            return 'uploads/patrimonio/nf/' . $nome_arquivo;
        }

        return null;
    }

    // ── Cadastro ──────────────────────────────────────────────────────────────

    /**
     * Cria um ou mais patrimônios.
     * Se quantidade > 1, gera múltiplos registros com PAT sequencial e grupo_pat.
     *
     * @param array $dados  Dados do formulário
     * @param array $files  $_FILES do formulário
     * @param int   $user_id ID do usuário logado
     * @return array ['success' => bool, 'message' => string, 'ids' => int[]]
     */
    public function criar(array $dados, array $files, int $user_id): array
    {
        $quantidade = max(1, (int)($dados['quantidade'] ?? 1));

        // Upload de foto e NF (compartilhados entre todos do lote)
        $foto      = null;
        $arquivo_nf = null;

        if (!empty($files['foto']['name'])) {
            $foto = $this->uploadFoto($files['foto']);
        }
        if (!empty($files['arquivo_nf']['name'])) {
            $arquivo_nf = $this->uploadNF($files['arquivo_nf']);
        }

        // Gerar números PAT
        $numeros = $this->gerarNumerosPatLote($quantidade);

        // Grupo para identificar o lote (quando quantidade > 1)
        $grupo_pat = $quantidade > 1 ? 'LOTE-' . date('YmdHis') : null;

        // Calcular próxima preventiva
        $proxima_preventiva = null;
        $tem_preventiva     = !empty($dados['tem_preventiva']) ? 1 : 0;
        if ($tem_preventiva && !empty($dados['periodicidade_preventiva']) && !empty($dados['data_compra'])) {
            $proxima_preventiva = $this->calcularProximaPreventiva(
                $dados['data_compra'],
                $dados['periodicidade_preventiva']
            );
        }

        $stmt = $this->conn->prepare("
            INSERT INTO patrimonio
                (numero_pat, descricao, classificacao, categoria, marca, modelo, numero_serie,
                 valor_compra, data_compra, fornecedor, numero_nf, arquivo_nf, foto,
                 localizacao, responsavel, observacoes, status,
                 grupo_pat, quantidade_lote, sequencia_lote,
                 tem_preventiva, periodicidade_preventiva, proxima_preventiva,
                 criado_por)
            VALUES
                (?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?)
        ");

        $ids = [];
        foreach ($numeros as $seq => $numero_pat) {
            $stmt->execute([
                $numero_pat,
                trim($dados['descricao'] ?? ''),
                $dados['classificacao'] ?? 'ativo',
                trim($dados['categoria'] ?? '') ?: null,
                trim($dados['marca']     ?? '') ?: null,
                trim($dados['modelo']    ?? '') ?: null,
                trim($dados['numero_serie'] ?? '') ?: null,
                (float)str_replace(['.', ','], ['', '.'], $dados['valor_compra'] ?? '0'),
                !empty($dados['data_compra']) ? $dados['data_compra'] : null,
                trim($dados['fornecedor'] ?? '') ?: null,
                trim($dados['numero_nf']  ?? '') ?: null,
                $arquivo_nf,
                $foto,
                trim($dados['localizacao']  ?? '') ?: null,
                trim($dados['responsavel']  ?? '') ?: null,
                trim($dados['observacoes']  ?? '') ?: null,
                $dados['status'] ?? 'ativo',
                $grupo_pat,
                $quantidade,
                $seq + 1,
                $tem_preventiva,
                $tem_preventiva ? ($dados['periodicidade_preventiva'] ?? null) : null,
                $proxima_preventiva,
                $user_id,
            ]);
            $ids[] = (int)$this->conn->lastInsertId();
        }

        $msg = $quantidade > 1
            ? "$quantidade patrimônios cadastrados com sucesso! Números: " . implode(', ', $numeros)
            : "Patrimônio {$numeros[0]} cadastrado com sucesso!";

        return ['success' => true, 'message' => $msg, 'ids' => $ids, 'numeros' => $numeros];
    }

    // ── Atualização ───────────────────────────────────────────────────────────

    public function atualizar(int $id, array $dados, array $files, int $user_id): array
    {
        $atual = $this->buscarPorId($id);
        if (!$atual) {
            return ['success' => false, 'message' => 'Patrimônio não encontrado.'];
        }

        // Upload de nova foto (se enviada)
        $foto = $atual['foto'];
        if (!empty($files['foto']['name'])) {
            $nova_foto = $this->uploadFoto($files['foto']);
            if ($nova_foto) {
                $foto = $nova_foto;
            }
        }

        // Upload de nova NF (se enviada)
        $arquivo_nf = $atual['arquivo_nf'];
        if (!empty($files['arquivo_nf']['name'])) {
            $nova_nf = $this->uploadNF($files['arquivo_nf']);
            if ($nova_nf) {
                $arquivo_nf = $nova_nf;
            }
        }

        $tem_preventiva = !empty($dados['tem_preventiva']) ? 1 : 0;
        $proxima_preventiva = $atual['proxima_preventiva'];

        if ($tem_preventiva && !empty($dados['periodicidade_preventiva'])) {
            $base = !empty($dados['data_compra']) ? $dados['data_compra'] : date('Y-m-d');
            $proxima_preventiva = $this->calcularProximaPreventiva($base, $dados['periodicidade_preventiva']);
        } elseif (!$tem_preventiva) {
            $proxima_preventiva = null;
        }

        $stmt = $this->conn->prepare("
            UPDATE patrimonio SET
                descricao              = ?,
                classificacao          = ?,
                categoria              = ?,
                marca                  = ?,
                modelo                 = ?,
                numero_serie           = ?,
                valor_compra           = ?,
                data_compra            = ?,
                fornecedor             = ?,
                numero_nf              = ?,
                arquivo_nf             = ?,
                foto                   = ?,
                localizacao            = ?,
                responsavel            = ?,
                observacoes            = ?,
                status                 = ?,
                tem_preventiva         = ?,
                periodicidade_preventiva = ?,
                proxima_preventiva     = ?,
                atualizado_por         = ?
            WHERE id = ?
        ");

        $stmt->execute([
            trim($dados['descricao'] ?? ''),
            $dados['classificacao'] ?? 'ativo',
            trim($dados['categoria'] ?? '') ?: null,
            trim($dados['marca']     ?? '') ?: null,
            trim($dados['modelo']    ?? '') ?: null,
            trim($dados['numero_serie'] ?? '') ?: null,
            (float)str_replace(['.', ','], ['', '.'], $dados['valor_compra'] ?? '0'),
            !empty($dados['data_compra']) ? $dados['data_compra'] : null,
            trim($dados['fornecedor'] ?? '') ?: null,
            trim($dados['numero_nf']  ?? '') ?: null,
            $arquivo_nf,
            $foto,
            trim($dados['localizacao']  ?? '') ?: null,
            trim($dados['responsavel']  ?? '') ?: null,
            trim($dados['observacoes']  ?? '') ?: null,
            $dados['status'] ?? 'ativo',
            $tem_preventiva,
            $tem_preventiva ? ($dados['periodicidade_preventiva'] ?? null) : null,
            $proxima_preventiva,
            $user_id,
            $id,
        ]);

        return ['success' => true, 'message' => "Patrimônio {$atual['numero_pat']} atualizado com sucesso!"];
    }

    // ── Listagem ──────────────────────────────────────────────────────────────

    public function listar(array $filtros = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['busca'])) {
            $where[]  = "(numero_pat LIKE ? OR descricao LIKE ? OR numero_serie LIKE ? OR numero_nf LIKE ?)";
            $busca    = '%' . $filtros['busca'] . '%';
            $params   = array_merge($params, [$busca, $busca, $busca, $busca]);
        }

        if (!empty($filtros['classificacao'])) {
            $where[]  = "classificacao = ?";
            $params[] = $filtros['classificacao'];
        }

        if (!empty($filtros['status'])) {
            $where[]  = "status = ?";
            $params[] = $filtros['status'];
        }

        if (!empty($filtros['categoria'])) {
            $where[]  = "categoria = ?";
            $params[] = $filtros['categoria'];
        }

        if (isset($filtros['tem_preventiva']) && $filtros['tem_preventiva'] !== '') {
            $where[]  = "tem_preventiva = ?";
            $params[] = (int)$filtros['tem_preventiva'];
        }

        $sql  = "SELECT * FROM patrimonio WHERE " . implode(' AND ', $where) . " ORDER BY numero_pat ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM patrimonio WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listarCategorias(): array
    {
        $stmt = $this->conn->query("SELECT DISTINCT categoria FROM patrimonio WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── Preventivas ───────────────────────────────────────────────────────────

    public function registrarPreventiva(int $patrimonio_id, array $dados, int $user_id): array
    {
        $stmt = $this->conn->prepare("
            INSERT INTO patrimonio_preventivas
                (patrimonio_id, data_realizada, descricao, tecnico, custo, observacoes, proxima_data, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $patrimonio_id,
            $dados['data_realizada'],
            trim($dados['descricao']   ?? '') ?: null,
            trim($dados['tecnico']     ?? '') ?: null,
            (float)str_replace(['.', ','], ['', '.'], $dados['custo'] ?? '0'),
            trim($dados['observacoes'] ?? '') ?: null,
            !empty($dados['proxima_data']) ? $dados['proxima_data'] : null,
            $user_id,
        ]);

        // Atualizar última preventiva e próxima no patrimônio
        $this->conn->prepare("
            UPDATE patrimonio
            SET ultima_preventiva  = ?,
                proxima_preventiva = ?
            WHERE id = ?
        ")->execute([
            $dados['data_realizada'],
            !empty($dados['proxima_data']) ? $dados['proxima_data'] : null,
            $patrimonio_id,
        ]);

        return ['success' => true, 'message' => 'Preventiva registrada com sucesso!'];
    }

    public function listarPreventivas(int $patrimonio_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT pp.*, u.name AS registrado_nome
            FROM patrimonio_preventivas pp
            LEFT JOIN users u ON u.id = pp.registrado_por
            WHERE pp.patrimonio_id = ?
            ORDER BY pp.data_realizada DESC
        ");
        $stmt->execute([$patrimonio_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Utilitários ───────────────────────────────────────────────────────────

    private function calcularProximaPreventiva(string $data_base, string $periodicidade): string
    {
        $dt = new DateTime($data_base);
        switch ($periodicidade) {
            case 'mensal':     $dt->modify('+1 month');  break;
            case 'bimestral':  $dt->modify('+2 months'); break;
            case 'trimestral': $dt->modify('+3 months'); break;
            case 'semestral':  $dt->modify('+6 months'); break;
            case 'anual':      $dt->modify('+1 year');   break;
        }
        return $dt->format('Y-m-d');
    }

    public function estatisticas(): array
    {
        $stmt = $this->conn->query("
            SELECT
                COUNT(*)                                        AS total,
                SUM(classificacao = 'imobilizado')              AS total_imobilizado,
                SUM(classificacao = 'ativo')                    AS total_ativo,
                SUM(status = 'ativo')                           AS total_ativos,
                SUM(status = 'em_manutencao')                   AS total_manutencao,
                SUM(status = 'baixado')                         AS total_baixados,
                SUM(tem_preventiva = 1)                         AS total_com_preventiva,
                SUM(tem_preventiva = 1 AND proxima_preventiva <= CURDATE()) AS preventivas_vencidas,
                COALESCE(SUM(valor_compra), 0)                  AS valor_total
            FROM patrimonio
            WHERE status != 'baixado'
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
