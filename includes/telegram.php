<?php
/**
 * Telegram Bot Integration
 * 
 * Classe para enviar notificações via Telegram Bot
 * Documentação: https://core.telegram.org/bots/api
 */

class TelegramBot {
    private $conn;
    public function __construct($conn = null) {
        $this->conn = $conn ?? getDBConnection();
    }
    
    /**
     * Envia mensagem via Telegram
     * 
     * @param int $estabelecimento_id ID do estabelecimento
     * @param string $message Mensagem a ser enviada
     * @param string $type Tipo de alerta (venda, volume_critico, vencimento_10d, vencimento_2d, vencido)
     * @param int|null $reference_id ID de referência (order_id ou tap_id)
     * @return bool
     */
    public function sendMessage($estabelecimento_id, $message, $type = 'info', $reference_id = null) {
        try {
            // Buscar configuração do Telegram para o estabelecimento
            $config = $this->getConfig($estabelecimento_id);
            
            if (!$config || !$config['status']) {
                Logger::info("Telegram não configurado ou desativado para estabelecimento $estabelecimento_id");
                return false;
            }
            
            // Verificar se o tipo de notificação está habilitado
            if (!$this->isNotificationEnabled($config, $type)) {
                Logger::info("Notificação tipo '$type' desativada para estabelecimento $estabelecimento_id");
                return false;
            }
            
            $bot_token = $config['bot_token'];
            $chat_id = $config['chat_id'];
            
            // Preparar dados para envio
            $data = [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ];
            
            // Enviar mensagem via API do Telegram
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response_data = json_decode($response, true);
            
            if ($http_code === 200 && isset($response_data['ok']) && $response_data['ok']) {
                // Registrar alerta enviado
                $this->logAlert($estabelecimento_id, $type, $reference_id, $message, 'sent', $response);
                
                Logger::info("Telegram: mensagem enviada com sucesso para estabelecimento $estabelecimento_id");
                return true;
            } else {
                // Registrar falha
                $this->logAlert($estabelecimento_id, $type, $reference_id, $message, 'failed', $response);
                
                Logger::error("Telegram: erro ao enviar mensagem: HTTP $http_code", ['response' => $response]);
                return false;
            }
            
        } catch (Exception $e) {
            Logger::error('Telegram: exceção ao enviar mensagem: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Busca configuração do Telegram para um estabelecimento
     */
    private function getConfig($estabelecimento_id) {
        $stmt = $this->conn->prepare("
            SELECT * FROM telegram_config 
            WHERE estabelecimento_id = ? AND status = 1
            LIMIT 1
        ");
        $stmt->execute([$estabelecimento_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verifica se um tipo de notificação está habilitado
     */
    private function isNotificationEnabled($config, $type) {
        switch ($type) {
            case 'venda':
                return (bool)$config['notificar_vendas'];
            case 'volume_critico':
                return (bool)$config['notificar_volume_critico'];
            case 'vencimento_10d':
            case 'vencimento_2d':
            case 'vencido':
                return (bool)$config['notificar_vencimento'];
            default:
                return true; // Outros tipos sempre habilitados
        }
    }
    
    /**
     * Registra alerta no histórico
     */
    private function logAlert($estabelecimento_id, $type, $reference_id, $message, $status, $response) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO telegram_alerts (estabelecimento_id, type, reference_id, message, status, response)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$estabelecimento_id, $type, $reference_id, $message, $status, $response]);
        } catch (Exception $e) {
            Logger::error('Telegram: erro ao registrar alerta: ' . $e->getMessage());
        }
    }
    
    /**
     * Formata mensagem de venda
     */
    public static function formatVendaMessage($order) {
        $emoji_metodo = [
            'PIX' => '💳',
            'CREDITO' => '💳',
            'DEBITO' => '💳',
            'CARTAO' => '💳'
        ];
        
        $emoji = $emoji_metodo[$order['method']] ?? '💰';
        
        $message = "<b>🍺 NOVA VENDA REALIZADA!</b>\n\n";
        $message .= "{$emoji} <b>Método:</b> " . $order['method'] . "\n";
        $message .= "💵 <b>Valor:</b> R$ " . number_format($order['valor'], 2, ',', '.') . "\n";
        $message .= "🍻 <b>Bebida:</b> " . $order['bebida_nome'] . "\n";
        $message .= "📏 <b>Quantidade:</b> " . $order['quantidade'] . " ml\n";
        
        if (!empty($order['cpf'])) {
            $message .= "👤 <b>CPF:</b> " . $order['cpf'] . "\n";
        }
        
        $message .= "📅 <b>Data:</b> " . date('d/m/Y H:i:s') . "\n";
        $message .= "🏪 <b>Estabelecimento:</b> " . $order['estabelecimento_nome'] . "\n";
        
        return $message;
    }
    
    /**
     * Formata mensagem de volume crítico
     */
    public static function formatVolumeCriticoMessage($tap) {
        $volume_restante = $tap['volume'] - $tap['volume_consumido'];
        $percentual = ($volume_restante / $tap['volume']) * 100;
        
        $message = "<b>⚠️ ALERTA: VOLUME CRÍTICO!</b>\n\n";
        $message .= "🍺 <b>Bebida:</b> " . $tap['bebida_nome'] . "\n";
        $message .= "🏭 <b>Marca:</b> " . $tap['bebida_marca'] . "\n";
        $message .= "📊 <b>Volume Restante:</b> " . number_format($volume_restante, 2, ',', '.') . " L\n";
        $message .= "📉 <b>Percentual:</b> " . number_format($percentual, 1, ',', '.') . "%\n";
        $message .= "🚨 <b>Volume Crítico:</b> " . number_format($tap['volume_critico'], 2, ',', '.') . " L\n";
        $message .= "🏪 <b>Estabelecimento:</b> " . $tap['estabelecimento_nome'] . "\n";
        $message .= "\n<i>⏰ Providencie a troca do barril!</i>";
        
        return $message;
    }
    
    /**
     * Formata mensagem de vencimento
     */
    public static function formatVencimentoMessage($tap, $dias) {
        $emoji_status = [
            'vencido' => '🔴',
            '2_dias' => '🟠',
            '10_dias' => '🟡'
        ];
        
        $titulo_status = [
            'vencido' => 'BARRIL VENCIDO',
            '2_dias' => 'VENCE EM 2 DIAS',
            '10_dias' => 'VENCE EM 10 DIAS'
        ];
        
        $status = $dias < 0 ? 'vencido' : ($dias <= 2 ? '2_dias' : '10_dias');
        $emoji = $emoji_status[$status];
        $titulo = $titulo_status[$status];
        
        $message = "<b>{$emoji} ALERTA: {$titulo}!</b>\n\n";
        $message .= "🍺 <b>Bebida:</b> " . $tap['bebida_nome'] . "\n";
        $message .= "🏭 <b>Marca:</b> " . $tap['bebida_marca'] . "\n";
        $message .= "📅 <b>Data de Vencimento:</b> " . date('d/m/Y', strtotime($tap['vencimento'])) . "\n";
        
        if ($dias < 0) {
            $message .= "⏰ <b>Vencido há:</b> " . abs($dias) . " dia(s)\n";
            $message .= "\n<i>🚫 Barril vencido! Remova imediatamente!</i>";
        } else {
            $message .= "⏰ <b>Dias restantes:</b> " . $dias . " dia(s)\n";
            $message .= "\n<i>⚠️ Planeje a substituição do barril!</i>";
        }
        
        $message .= "\n🏪 <b>Estabelecimento:</b> " . $tap['estabelecimento_nome'];
        
        return $message;
    }
    
    /**
     * Testa conexão com o bot
     */
    public static function testConnection($bot_token) {
        try {
            $url = "https://api.telegram.org/bot{$bot_token}/getMe";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if ($http_code === 200 && isset($data['ok']) && $data['ok']) {
                return [
                    'success' => true,
                    'bot_name' => $data['result']['username'] ?? 'Unknown',
                    'bot_id' => $data['result']['id'] ?? null
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $data['description'] ?? 'Erro desconhecido'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Envia mensagem de teste
     */
    public static function sendTestMessage($bot_token, $chat_id) {
        try {
            $message = "<b>🤖 Teste de Conexão - Chopp On Tap</b>\n\n";
            $message .= "✅ Bot configurado com sucesso!\n";
            $message .= "📱 Você receberá notificações de:\n";
            $message .= "  • Vendas realizadas\n";
            $message .= "  • Volume crítico de barris\n";
            $message .= "  • Alertas de vencimento\n\n";
            $message .= "🍺 <i>Sistema Chopp On Tap</i>";
            
            $data = [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];
            
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response_data = json_decode($response, true);
            
            if ($http_code === 200 && isset($response_data['ok']) && $response_data['ok']) {
                return ['success' => true];
            } else {
                return [
                    'success' => false,
                    'error' => $response_data['description'] ?? 'Erro ao enviar mensagem'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>
