<?php
/**
 * API: Histórico de Interações CRM
 * Retorna todas as interações de um Lead ou Oportunidade em ordem cronológica decrescente.
 * Inclui detalhes completos de transferências (de quem, para quem, motivo).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once '../includes/config.php';
require_once '../includes/auth.php';

// Autenticação obrigatória
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$id   = intval($_GET['id'] ?? 0);

if (!in_array($tipo, ['lead', 'oportunidade']) || !$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

try {
    $conn = getDBConnection();

    // Verificar se o registro existe e se o usuário tem acesso
    $is_admin = isAdminGeral();
    $estab_id = getEstabelecimentoId();

    if ($tipo === 'lead') {
        $stmt = $conn->prepare("SELECT id, estabelecimento_id FROM crm_leads WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT id, estabelecimento_id FROM crm_oportunidades WHERE id = ?");
    }
    $stmt->execute([$id]);
    $registro = $stmt->fetch();

    if (!$registro) {
        http_response_code(404);
        echo json_encode(['error' => 'Registro não encontrado']);
        exit;
    }

    // Verificar permissão de estabelecimento
    if (!$is_admin && $registro['estabelecimento_id'] != $estab_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso negado']);
        exit;
    }

    // Buscar interações com dados completos
    $stmt = $conn->prepare("
        SELECT
            i.id,
            i.tipo,
            i.descricao,
            i.created_at,
            -- Quem registrou
            u.name                  AS user_nome,
            -- Transferência: de quem
            ud.name                 AS transferencia_de_nome,
            i.transferencia_de      AS transferencia_de_id,
            -- Transferência: para quem
            up.name                 AS transferencia_para_nome,
            i.transferencia_para    AS transferencia_para_id,
            -- Motivo da transferência
            i.motivo_transferencia
        FROM crm_interacoes i
        LEFT JOIN users u  ON i.user_id          = u.id
        LEFT JOIN users ud ON i.transferencia_de  = ud.id
        LEFT JOIN users up ON i.transferencia_para = up.id
        WHERE i.tipo_registro = ? AND i.registro_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$tipo, $id]);
    $interacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enriquecer dados de transferência na descrição se necessário
    foreach ($interacoes as &$item) {
        // Garantir que a descrição de transferência tenha os nomes formatados
        if ($item['tipo'] === 'transferencia') {
            $de   = $item['transferencia_de_nome']   ?? 'Desconhecido';
            $para = $item['transferencia_para_nome'] ?? 'Desconhecido';
            // Se a descrição não tiver os nomes (registros antigos), reconstruir
            if (strpos($item['descricao'], 'transferid') === false) {
                $item['descricao'] = "Transferido de <strong>{$de}</strong> para <strong>{$para}</strong>.";
            }
        }
    }
    unset($item);

    echo json_encode($interacoes, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
}
