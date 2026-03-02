#!/usr/bin/env php
<?php
/**
 * Script para testar notificação de contas manualmente
 * Uso: php testar_notificacao_manual.php
 */

// Incluir configurações
require_once __DIR__ . '/../includes/config.php';

echo "===========================================\n";
echo "  TESTE MANUAL DE NOTIFICAÇÃO TELEGRAM\n";
echo "===========================================\n\n";

try {
    $conn = getDBConnection();
    
    // Buscar estabelecimentos com Telegram configurado
    echo "1. Buscando estabelecimentos com Telegram configurado...\n";
    $stmt = $conn->query("
        SELECT e.id, e.name, tc.bot_token, tc.chat_id, tc.status
        FROM estabelecimentos e
        INNER JOIN telegram_config tc ON e.id = tc.estabelecimento_id
    ");
    $estabelecimentos = $stmt->fetchAll();
    
    if (empty($estabelecimentos)) {
        echo "   ❌ Nenhum estabelecimento com Telegram configurado!\n";
        echo "\n   Configure o Telegram em: Admin → Telegram\n\n";
        exit(1);
    }
    
    echo "   ✓ Encontrados " . count($estabelecimentos) . " estabelecimento(s)\n\n";
    
    foreach ($estabelecimentos as $idx => $estab) {
        echo "   [" . ($idx + 1) . "] {$estab['name']}\n";
        echo "       Bot Token: " . substr($estab['bot_token'], 0, 20) . "...\n";
        echo "       Chat ID: {$estab['chat_id']}\n";
        echo "       Status: " . ($estab['status'] ? 'Ativo' : 'Inativo') . "\n\n";
    }
    
    // Selecionar estabelecimento
    if (count($estabelecimentos) > 1) {
        echo "Selecione o estabelecimento (1-" . count($estabelecimentos) . "): ";
        $escolha = trim(fgets(STDIN));
        $estabelecimento = $estabelecimentos[$escolha - 1] ?? $estabelecimentos[0];
    } else {
        $estabelecimento = $estabelecimentos[0];
    }
    
    echo "\n2. Buscando contas do estabelecimento: {$estabelecimento['name']}...\n";
    
    // Buscar contas pendentes
    $stmt = $conn->prepare("
        SELECT * FROM contas_pagar
        WHERE estabelecimento_id = ?
        AND status = 'pendente'
        ORDER BY data_vencimento ASC
        LIMIT 10
    ");
    $stmt->execute([$estabelecimento['id']]);
    $contas = $stmt->fetchAll();
    
    if (empty($contas)) {
        echo "   ❌ Nenhuma conta pendente encontrada!\n\n";
        exit(0);
    }
    
    echo "   ✓ Encontradas " . count($contas) . " conta(s) pendente(s)\n\n";
    
    foreach ($contas as $idx => $conta) {
        $vencimento = date('d/m/Y', strtotime($conta['data_vencimento']));
        $valor = number_format($conta['valor'], 2, ',', '.');
        echo "   [" . ($idx + 1) . "] {$conta['descricao']} - R$ {$valor} - Vence em: {$vencimento}\n";
    }
    
    echo "\n3. Preparando mensagem de teste...\n";
    
    // Montar mensagem
    $mensagem = "🧪 <b>TESTE MANUAL DE NOTIFICAÇÃO</b>\n\n";
    $mensagem .= "📅 Data: " . date('d/m/Y H:i:s') . "\n";
    $mensagem .= "🏪 Estabelecimento: {$estabelecimento['name']}\n\n";
    $mensagem .= "📋 <b>Contas Pendentes:</b>\n\n";
    
    foreach ($contas as $conta) {
        $mensagem .= "━━━━━━━━━━━━━━━━━━━━\n";
        $mensagem .= "📋 <b>{$conta['descricao']}</b>\n";
        $mensagem .= "🏷️ Tipo: {$conta['tipo']}\n";
        $mensagem .= "💰 Valor: R$ " . number_format($conta['valor'], 2, ',', '.') . "\n";
        $mensagem .= "📆 Vencimento: " . date('d/m/Y', strtotime($conta['data_vencimento'])) . "\n";
        
        if ($conta['codigo_barras']) {
            $mensagem .= "📊 Código: <code>{$conta['codigo_barras']}</code>\n";
        }
        
        if ($conta['link_pagamento']) {
            $mensagem .= "🔗 Link: {$conta['link_pagamento']}\n";
        }
        
        $mensagem .= "\n";
    }
    
    $mensagem .= "✅ Este é um teste manual do sistema de notificações!";
    
    echo "   ✓ Mensagem preparada (" . strlen($mensagem) . " caracteres)\n\n";
    
    echo "4. Enviando mensagem via Telegram...\n";
    
    // Enviar via Telegram
    $url = "https://api.telegram.org/bot{$estabelecimento['bot_token']}/sendMessage";
    
    $data = [
        'chat_id' => $estabelecimento['chat_id'],
        'text' => $mensagem,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    echo "\n5. Resultado:\n";
    
    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        
        if ($response_data && $response_data['ok']) {
            echo "   ✅ SUCESSO! Mensagem enviada para o Telegram!\n";
            echo "   Message ID: {$response_data['result']['message_id']}\n";
            echo "\n   Verifique seu Telegram agora!\n\n";
        } else {
            echo "   ❌ ERRO na resposta do Telegram:\n";
            echo "   " . ($response_data['description'] ?? 'Erro desconhecido') . "\n\n";
        }
    } else {
        echo "   ❌ ERRO HTTP {$http_code}\n";
        if ($curl_error) {
            echo "   cURL Error: {$curl_error}\n";
        }
        
        $error_response = json_decode($response, true);
        if ($error_response && isset($error_response['description'])) {
            echo "   Telegram Error: {$error_response['description']}\n";
        }
        echo "\n";
    }
    
    echo "===========================================\n";
    echo "  TESTE CONCLUÍDO\n";
    echo "===========================================\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n\n";
    exit(1);
}

exit(0);
?>
