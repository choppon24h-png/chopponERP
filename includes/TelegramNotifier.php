<?php
/**
 * ========================================
 * TELEGRAM NOTIFIER - Sistema de Notificações Automáticas
 * ========================================
 * 
 * Classe robusta para envio de notificações automáticas via Telegram
 * Desenvolvida seguindo melhores práticas de desenvolvimento
 * 
 * @author ChopponERP Team
 * @version 2.0.0
 * @date 2026-01-12
 * 
 * Funcionalidades:
 * - Verificação de estoque mínimo
 * - Verificação de contas a pagar vencendo hoje
 * - Verificação de promoções ativas
 * - Envio de mensagens formatadas com emojis e Markdown
 * - Tratamento robusto de erros
 * - Log completo de operações
 * - Suporte a múltiplos estabelecimentos
 */

class TelegramNotifier {
    
    /**
     * @var PDO Conexão com banco de dados
     */
    private $conn;
    
    /**
     * @var string Token do bot Telegram
     */
    private $botToken;
    
    /**
     * @var string Chat ID do Telegram
     */
    private $chatId;
    
    /**
     * @var int ID do estabelecimento
     */
    private $estabelecimentoId;
    
    /**
     * @var array Configurações do Telegram
     */
    private $config;
    
    /**
     * @var array Contador de alertas enviados
     */
    private $contadores = [
        'estoque' => 0,
        'contas' => 0,
        'promocoes' => 0,
        'erros' => 0
    ];
    
    /**
     * Construtor - Recebe conexão PDO
     * 
     * @param PDO $conn Conexão PDO com banco de dados
     * @param int|null $estabelecimentoId ID do estabelecimento (null = todos)
     * @throws Exception Se configuração não for encontrada
     */
    public function __construct(PDO $conn, $estabelecimentoId = null) {
        $this->conn = $conn;
        $this->estabelecimentoId = $estabelecimentoId;
        
        // Carregar configuração do Telegram
        $this->carregarConfiguracao();
    }
    
    /**
     * Carregar configuração do Telegram do banco de dados
     * 
     * @throws Exception Se configuração não for encontrada ou inativa
     */
    private function carregarConfiguracao() {
        try {
            $sql = "SELECT * FROM telegram_config WHERE status = 1";
            
            if ($this->estabelecimentoId) {
                $sql .= " AND estabelecimento_id = :estabelecimento_id";
            }
            
            $sql .= " LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            
            if ($this->estabelecimentoId) {
                $stmt->bindParam(':estabelecimento_id', $this->estabelecimentoId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$this->config) {
                throw new Exception('Configuração do Telegram não encontrada ou inativa. Configure em Admin → Integrações → Telegram');
            }
            
            $this->botToken = $this->config['bot_token'];
            $this->chatId = $this->config['chat_id'];
            $this->estabelecimentoId = $this->config['estabelecimento_id'];
            
            $this->log('INFO', 'Configuração carregada com sucesso', [
                'estabelecimento_id' => $this->estabelecimentoId,
                'chat_id' => $this->chatId
            ]);
            
        } catch (PDOException $e) {
            throw new Exception('Erro ao carregar configuração do Telegram: ' . $e->getMessage());
        }
    }
    
    /**
     * Verificar produtos com estoque mínimo
     * 
     * Busca produtos onde estoque_atual <= estoque_minimo
     * e envia alerta para cada produto encontrado
     * 
     * @return int Quantidade de alertas enviados
     */
    public function verificarEstoqueMinimo() {
        try {
            $sql = "
                SELECT 
                    p.id,
                    p.codigo,
                    p.nome,
                    p.estoque_atual,
                    p.estoque_minimo,
                    p.unidade,
                    e.name as estabelecimento_nome
                FROM estoque_produtos p
                INNER JOIN estabelecimentos e ON p.estabelecimento_id = e.id
                WHERE p.estabelecimento_id = :estabelecimento_id
                  AND p.estoque_atual <= p.estoque_minimo
                  AND p.estoque_minimo > 0
                  AND p.ativo = 1
                ORDER BY (p.estoque_atual / NULLIF(p.estoque_minimo, 1)) ASC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':estabelecimento_id', $this->estabelecimentoId, PDO::PARAM_INT);
            $stmt->execute();
            
            $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($produtos);
            
            if ($total === 0) {
                $this->log('INFO', 'Nenhum produto com estoque mínimo encontrado');
                return 0;
            }
            
            $this->log('INFO', "Encontrados {$total} produto(s) com estoque mínimo");
            
            // Enviar alerta consolidado
            $mensagem = $this->formatarMensagemEstoque($produtos);
            
            if ($this->enviarMensagem($mensagem)) {
                $this->contadores['estoque']++;
                $this->log('SUCCESS', "Alerta de estoque enviado com sucesso ({$total} produtos)");
            }
            
            return $this->contadores['estoque'];
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Erro ao verificar estoque mínimo: ' . $e->getMessage());
            $this->contadores['erros']++;
            return 0;
        }
    }
    
    /**
     * Verificar contas a pagar vencendo hoje
     * 
     * Busca contas onde data_vencimento = hoje e status = 'pendente'
     * 
     * @return int Quantidade de alertas enviados
     */
    public function verificarContasPagar() {
        try {
            $hoje = date('Y-m-d');
            
            $sql = "
                SELECT 
                    cp.id,
                    cp.descricao,
                    cp.tipo,
                    cp.valor,
                    cp.data_vencimento,
                    cp.codigo_barras,
                    cp.link_pagamento,
                    e.name as estabelecimento_nome
                FROM contas_pagar cp
                INNER JOIN estabelecimentos e ON cp.estabelecimento_id = e.id
                WHERE cp.estabelecimento_id = :estabelecimento_id
                  AND cp.data_vencimento = :hoje
                  AND cp.status = 'pendente'
                ORDER BY cp.valor DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':estabelecimento_id', $this->estabelecimentoId, PDO::PARAM_INT);
            $stmt->bindParam(':hoje', $hoje, PDO::PARAM_STR);
            $stmt->execute();
            
            $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($contas);
            
            if ($total === 0) {
                $this->log('INFO', 'Nenhuma conta a pagar vencendo hoje');
                return 0;
            }
            
            $this->log('INFO', "Encontradas {$total} conta(s) vencendo hoje");
            
            // Enviar alerta consolidado
            $mensagem = $this->formatarMensagemContas($contas);
            
            if ($this->enviarMensagem($mensagem)) {
                $this->contadores['contas']++;
                $this->log('SUCCESS', "Alerta de contas enviado com sucesso ({$total} contas)");
            }
            
            return $this->contadores['contas'];
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Erro ao verificar contas a pagar: ' . $e->getMessage());
            $this->contadores['erros']++;
            return 0;
        }
    }
    
    /**
     * Verificar promoções ativas hoje
     * 
     * Busca promoções onde data_inicio <= hoje <= data_fim
     * 
     * @return int Quantidade de alertas enviados
     */
    public function verificarPromocoes() {
        try {
            $hoje = date('Y-m-d H:i:s');
            
            $sql = "
                SELECT 
                    p.id,
                    p.nome,
                    p.descricao,
                    p.data_inicio,
                    p.data_fim,
                    p.tipo_regra,
                    p.cupons,
                    p.cashback_valor,
                    p.cashback_ml,
                    e.name as estabelecimento_nome
                FROM promocoes p
                INNER JOIN estabelecimentos e ON p.estabelecimento_id = e.id
                WHERE p.estabelecimento_id = :estabelecimento_id
                  AND p.data_inicio <= :hoje
                  AND p.data_fim >= :hoje
                  AND p.ativo = 1
                ORDER BY p.data_inicio DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':estabelecimento_id', $this->estabelecimentoId, PDO::PARAM_INT);
            $stmt->bindParam(':hoje', $hoje, PDO::PARAM_STR);
            $stmt->execute();
            
            $promocoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($promocoes);
            
            if ($total === 0) {
                $this->log('INFO', 'Nenhuma promoção ativa hoje');
                return 0;
            }
            
            $this->log('INFO', "Encontradas {$total} promoção(ões) ativa(s) hoje");
            
            // Enviar alerta consolidado
            $mensagem = $this->formatarMensagemPromocoes($promocoes);
            
            if ($this->enviarMensagem($mensagem)) {
                $this->contadores['promocoes']++;
                $this->log('SUCCESS', "Alerta de promoções enviado com sucesso ({$total} promoções)");
            }
            
            return $this->contadores['promocoes'];
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Erro ao verificar promoções: ' . $e->getMessage());
            $this->contadores['erros']++;
            return 0;
        }
    }
    
    /**
     * Formatar mensagem de estoque mínimo
     * 
     * @param array $produtos Lista de produtos
     * @return string Mensagem formatada
     */
    private function formatarMensagemEstoque($produtos) {
        $total = count($produtos);
        
        $mensagem = "🚨 *ALERTA: ESTOQUE MÍNIMO*\n\n";
        $mensagem .= "⚠️ *{$total} produto(s)* atingiram o estoque mínimo:\n\n";
        
        foreach ($produtos as $i => $produto) {
            $percentual = ($produto['estoque_atual'] / max($produto['estoque_minimo'], 1)) * 100;
            $emoji = $percentual <= 50 ? '🔴' : '🟡';
            
            $mensagem .= "{$emoji} *" . ($i + 1) . ". " . $produto['nome'] . "*\n";
            $mensagem .= "   📦 Estoque: *{$produto['estoque_atual']} {$produto['unidade']}*\n";
            $mensagem .= "   📊 Mínimo: {$produto['estoque_minimo']} {$produto['unidade']}\n";
            $mensagem .= "   📉 Percentual: " . number_format($percentual, 1) . "%\n\n";
        }
        
        $mensagem .= "🏪 *Estabelecimento:* {$produtos[0]['estabelecimento_nome']}\n";
        $mensagem .= "📅 *Data:* " . date('d/m/Y H:i') . "\n\n";
        $mensagem .= "💡 _Providencie a reposição dos produtos!_";
        
        return $mensagem;
    }
    
    /**
     * Formatar mensagem de contas a pagar
     * 
     * @param array $contas Lista de contas
     * @return string Mensagem formatada
     */
    private function formatarMensagemContas($contas) {
        $total = count($contas);
        $valorTotal = array_sum(array_column($contas, 'valor'));
        
        $mensagem = "💰 *ALERTA: CONTAS A PAGAR HOJE*\n\n";
        $mensagem .= "📋 *{$total} conta(s)* vencem hoje:\n\n";
        
        foreach ($contas as $i => $conta) {
            $mensagem .= "💳 *" . ($i + 1) . ". " . $conta['descricao'] . "*\n";
            $mensagem .= "   🏷️ Tipo: {$conta['tipo']}\n";
            $mensagem .= "   💵 Valor: *R$ " . number_format($conta['valor'], 2, ',', '.') . "*\n";
            $mensagem .= "   📅 Vencimento: " . date('d/m/Y', strtotime($conta['data_vencimento'])) . "\n";
            
            if ($conta['codigo_barras']) {
                $mensagem .= "   🔢 Cód. Barras: `{$conta['codigo_barras']}`\n";
            }
            
            if ($conta['link_pagamento']) {
                $mensagem .= "   🔗 [Link de Pagamento]({$conta['link_pagamento']})\n";
            }
            
            $mensagem .= "\n";
        }
        
        $mensagem .= "💰 *Valor Total:* R$ " . number_format($valorTotal, 2, ',', '.') . "\n";
        $mensagem .= "🏪 *Estabelecimento:* {$contas[0]['estabelecimento_nome']}\n";
        $mensagem .= "📅 *Data:* " . date('d/m/Y H:i') . "\n\n";
        $mensagem .= "⚠️ _Não esqueça de efetuar os pagamentos!_";
        
        return $mensagem;
    }
    
    /**
     * Formatar mensagem de promoções
     * 
     * @param array $promocoes Lista de promoções
     * @return string Mensagem formatada
     */
    private function formatarMensagemPromocoes($promocoes) {
        $total = count($promocoes);
        
        $mensagem = "🎉 *PROMOÇÕES ATIVAS HOJE*\n\n";
        $mensagem .= "🎁 *{$total} promoção(ões)* ativa(s):\n\n";
        
        foreach ($promocoes as $i => $promo) {
            $mensagem .= "🎯 *" . ($i + 1) . ". " . $promo['nome'] . "*\n";
            
            if ($promo['descricao']) {
                $mensagem .= "   📝 {$promo['descricao']}\n";
            }
            
            $mensagem .= "   📅 Início: " . date('d/m/Y H:i', strtotime($promo['data_inicio'])) . "\n";
            $mensagem .= "   📅 Fim: " . date('d/m/Y H:i', strtotime($promo['data_fim'])) . "\n";
            $mensagem .= "   🏷️ Tipo: " . ucfirst($promo['tipo_regra']) . "\n";
            
            if ($promo['cupons']) {
                $mensagem .= "   🎫 Cupons: {$promo['cupons']}\n";
            }
            
            if ($promo['cashback_valor']) {
                $mensagem .= "   💰 Cashback: R$ " . number_format($promo['cashback_valor'], 2, ',', '.') . "\n";
            }
            
            if ($promo['cashback_ml']) {
                $mensagem .= "   🍺 ML liberados: {$promo['cashback_ml']} ml\n";
            }
            
            $mensagem .= "\n";
        }
        
        $mensagem .= "🏪 *Estabelecimento:* {$promocoes[0]['estabelecimento_nome']}\n";
        $mensagem .= "📅 *Data:* " . date('d/m/Y H:i') . "\n\n";
        $mensagem .= "🎊 _Aproveite as promoções!_";
        
        return $mensagem;
    }
    
    /**
     * Enviar mensagem para o Telegram
     * 
     * Método privado que usa curl para enviar mensagem formatada
     * 
     * @param string $mensagem Mensagem a ser enviada
     * @return bool True se enviado com sucesso, False caso contrário
     */
    private function enviarMensagem($mensagem) {
        try {
            $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
            
            $data = [
                'chat_id' => $this->chatId,
                'text' => $mensagem,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];
            
            // Usar curl para enviar
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new Exception("Erro CURL: {$error}");
            }
            
            $responseData = json_decode($response, true);
            
            if ($httpCode === 200 && isset($responseData['ok']) && $responseData['ok']) {
                $this->registrarEnvio('success', $mensagem, $response);
                return true;
            } else {
                $errorMsg = $responseData['description'] ?? 'Erro desconhecido';
                throw new Exception("Erro API Telegram (HTTP {$httpCode}): {$errorMsg}");
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Erro ao enviar mensagem: ' . $e->getMessage());
            $this->registrarEnvio('failed', $mensagem, $e->getMessage());
            $this->contadores['erros']++;
            return false;
        }
    }
    
    /**
     * Registrar envio de mensagem no banco de dados
     * 
     * @param string $status Status do envio (success/failed)
     * @param string $mensagem Mensagem enviada
     * @param string $response Resposta da API
     */
    private function registrarEnvio($status, $mensagem, $response) {
        try {
            $sql = "
                INSERT INTO telegram_alerts 
                (estabelecimento_id, type, message, status, response, created_at)
                VALUES 
                (:estabelecimento_id, 'cron_alert', :message, :status, :response, NOW())
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':estabelecimento_id', $this->estabelecimentoId, PDO::PARAM_INT);
            $stmt->bindParam(':message', $mensagem, PDO::PARAM_STR);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':response', $response, PDO::PARAM_STR);
            $stmt->execute();
            
        } catch (PDOException $e) {
            $this->log('ERROR', 'Erro ao registrar envio: ' . $e->getMessage());
        }
    }
    
    /**
     * Registrar log de operações
     * 
     * @param string $level Nível do log (INFO, SUCCESS, ERROR, WARNING)
     * @param string $message Mensagem do log
     * @param array $context Contexto adicional
     */
    private function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $logMessage = "[{$timestamp}] [{$level}] {$message}";
        if ($contextStr) {
            $logMessage .= " | Context: {$contextStr}";
        }
        
        // Escrever em arquivo de log
        $logFile = dirname(__DIR__) . '/logs/telegram_notifier_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        @file_put_contents($logFile, $logMessage . "\n", FILE_APPEND);
        
        // Também exibir no console (útil para CRON)
        echo $logMessage . "\n";
    }
    
    /**
     * Obter contadores de alertas
     * 
     * @return array Contadores de alertas enviados
     */
    public function getContadores() {
        return $this->contadores;
    }
    
    /**
     * Obter total de alertas enviados
     * 
     * @return int Total de alertas
     */
    public function getTotalAlertas() {
        return $this->contadores['estoque'] + 
               $this->contadores['contas'] + 
               $this->contadores['promocoes'];
    }
}
?>
