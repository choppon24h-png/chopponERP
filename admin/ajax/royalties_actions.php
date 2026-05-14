<?php
/**
 * Ações AJAX para Royalties
 * Processa: gerar link, enviar e-mail, buscar dados, cancelar,
 *           pagamento_manual, editar
 */

require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/RoyaltiesManager.php';

header('Content-Type: application/json');

$conn = getDBConnection();
$royaltiesManager = new RoyaltiesManager($conn);

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ===== BUSCAR ROYALTY =====
        case 'buscar':
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $royalty = $royaltiesManager->buscarPorId($id);
            if (!$royalty) throw new Exception('Royalty não encontrado');
            echo json_encode(['success' => true, 'royalty' => $royalty]);
            break;

        // ===== GERAR LINK =====
        case 'gerar_link':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $resultado = $royaltiesManager->gerarPaymentLink($id);
            echo json_encode($resultado);
            break;

        // ===== ENVIAR E-MAIL =====
        case 'enviar_email':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $resultado = $royaltiesManager->enviarEmail($id);
            echo json_encode($resultado);
            break;

        // ===== GERAR E ENVIAR TUDO =====
        case 'gerar_e_enviar':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $resultadoLink = $royaltiesManager->gerarPaymentLink($id);
            if (!$resultadoLink['success']) { echo json_encode($resultadoLink); break; }
            $resultadoEmail = $royaltiesManager->enviarEmail($id);
            if (!$resultadoEmail['success']) {
                echo json_encode(['success' => false, 'message' => 'Link gerado, mas erro ao enviar e-mail: ' . $resultadoEmail['message']]);
                break;
            }
            echo json_encode(['success' => true, 'message' => 'Link gerado e e-mail enviado com sucesso!', 'payment_link' => $resultadoLink['payment_link']]);
            break;

        // ===== PAGAMENTO MANUAL =====
        case 'pagamento_manual':
            if (!isAdminGeral()) throw new Exception('Acesso negado.');
            $id             = intval($_POST['id'] ?? 0);
            $data_pagamento = trim($_POST['data_pagamento'] ?? '');
            $valor_pago     = floatval($_POST['valor_pago'] ?? 0);
            $observacao     = trim($_POST['observacao'] ?? '');
            if (!$id || !$data_pagamento || $valor_pago <= 0) throw new Exception('Dados incompletos. Informe data e valor.');
            // Verificar status atual
            $chk = $conn->prepare('SELECT status FROM royalties WHERE id = ?');
            $chk->execute([$id]);
            $row = $chk->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Royalty não encontrado.');
            if (in_array($row['status'], ['pago','conciliado','pagamento_manual'])) {
                throw new Exception('Este royalty já está marcado como pago.');
            }
            // Garantir colunas extras (migration automática)
            try {
                $cols = $conn->query("SHOW COLUMNS FROM royalties LIKE 'data_pagamento'")->fetchAll();
                if (empty($cols)) {
                    $conn->exec("ALTER TABLE royalties
                        ADD COLUMN data_pagamento DATE NULL COMMENT 'Data efetiva do pagamento',
                        ADD COLUMN valor_pago DECIMAL(10,2) NULL COMMENT 'Valor efetivamente pago',
                        ADD COLUMN observacoes_pagamento TEXT NULL COMMENT 'Observações do pagamento'
                    ");
                }
            } catch (\Exception $ex) { /* ignorar se já existir */ }
            $stmt = $conn->prepare("
                UPDATE royalties
                SET status = 'pagamento_manual',
                    data_pagamento = ?,
                    valor_pago = ?,
                    observacoes_pagamento = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$data_pagamento, $valor_pago, $observacao, $id]);
            echo json_encode(['success' => true, 'message' => 'Pagamento manual registrado com sucesso!']);
            break;

        // ===== EDITAR ROYALTY =====
        case 'editar':
            if (!isAdminGeral()) throw new Exception('Acesso negado.');
            $id                      = intval($_POST['id'] ?? 0);
            $mes_referencia          = trim($_POST['mes_referencia'] ?? '');
            $valor_faturamento_bruto = floatval($_POST['valor_faturamento_bruto'] ?? 0);
            $percentual_royalties    = floatval($_POST['percentual_royalties'] ?? 0);
            $valor_royalties         = floatval($_POST['valor_royalties'] ?? 0);
            $data_vencimento         = trim($_POST['data_vencimento'] ?? '');
            $observacoes             = trim($_POST['observacoes'] ?? '');
            if (!$id) throw new Exception('ID inválido.');
            // Verificar status
            $chk = $conn->prepare('SELECT status FROM royalties WHERE id = ?');
            $chk->execute([$id]);
            $row = $chk->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Royalty não encontrado.');
            if (in_array($row['status'], ['pago','conciliado','pagamento_manual','cancelado'])) {
                throw new Exception('Não é possível editar um royalty já concluído ou cancelado.');
            }
            // Formatar mes_referencia para DATE (YYYY-MM-01)
            $mes_date = $mes_referencia ? $mes_referencia . '-01' : null;
            $stmt = $conn->prepare("
                UPDATE royalties
                SET mes_referencia = ?,
                    valor_faturamento_bruto = ?,
                    percentual_royalties = ?,
                    valor_royalties = ?,
                    data_vencimento = ?,
                    observacoes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$mes_date, $valor_faturamento_bruto, $percentual_royalties, $valor_royalties, $data_vencimento, $observacoes, $id]);
            echo json_encode(['success' => true, 'message' => 'Royalty atualizado com sucesso!']);
            break;

        // ===== CANCELAR ROYALTY =====
        case 'cancelar':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) throw new Exception('ID inválido');
            $stmt = $conn->prepare("UPDATE royalties SET status = 'cancelado', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Royalty cancelado com sucesso']);
            break;

        default:
            throw new Exception('Ação inválida');
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
