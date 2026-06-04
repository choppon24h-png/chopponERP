<?php
/**
 * AJAX: Verificação periódica de contas a vencer
 * Retorna JSON com contagem de contas vencidas e vencendo hoje
 */
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

try {
    $conn = getDBConnection();

    $params = [];
    $where  = "WHERE c.status = 'pendente'";

    if (!isAdminGeral()) {
        $where   .= " AND c.estabelecimento_id = ?";
        $params[] = getEstabelecimentoId();
    }

    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN DATEDIFF(c.data_vencimento, CURDATE()) < 0  THEN 1 ELSE 0 END) AS vencidas,
            SUM(CASE WHEN DATEDIFF(c.data_vencimento, CURDATE()) = 0  THEN 1 ELSE 0 END) AS hoje,
            SUM(CASE WHEN DATEDIFF(c.data_vencimento, CURDATE()) <= 0 THEN c.valor ELSE 0 END) AS total_vencido
        FROM contas_pagar c
        $where
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $vencidas      = (int)($row['vencidas'] ?? 0);
    $hoje          = (int)($row['hoje']     ?? 0);
    $total_vencido = (float)($row['total_vencido'] ?? 0);

    echo json_encode([
        'vencidas'          => $vencidas,
        'hoje'              => $hoje,
        'total_urgentes'    => $vencidas + $hoje,
        'total_vencido'     => $total_vencido,
        'total_vencido_fmt' => number_format($total_vencido, 2, ',', '.'),
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
