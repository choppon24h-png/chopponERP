<?php
/**
 * Integração com SumUp
 */

class SumUpIntegration {
    private $token;
    private $merchant_code;
    private $checkout_url;
    private $merchant_url;
    
    public function __construct() {
        $this->token = 'Bearer ' . SUMUP_TOKEN;
        $this->merchant_code = SUMUP_MERCHANT_CODE;
        $this->checkout_url = SUMUP_CHECKOUT_URL;
        $this->merchant_url = SUMUP_MERCHANT_URL . $this->merchant_code;
    }
    
    /**
     * Adiciona uma leitora de cartão
     */
    public function addReader($pairing_code) {
        $url = $this->merchant_url . '/readers';
        $data = [
            'pairing_code' => $pairing_code,
            'name' => "{$pairing_code}RE"
        ];
        
        $response = $this->makeRequest($url, 'POST', $data);
        
        if ($response['status'] === 201 && isset($response['data']->id)) {
            return $response['data']->id;
        }
        
        return false;
    }
    
    /**
     * Cria checkout para PIX
     */
    public function createCheckoutPix($order_data) {
        $body = [
            'checkout_reference' => 'CO' . $order_data['id'],
            'amount' => $order_data['valor'],
            'currency' => 'BRL',
            'merchant_code' => $this->merchant_code,
            'description' => $order_data['descricao'],
            'return_url' => SITE_URL . '/api/webhook.php',
            'date' => gmdate('Y-m-d\TH:i:s') . '+00:00',
            'valid_until' => date('Y-m-d\TH:i:s', strtotime(gmdate('Y-m-d H:i:s') . ' +2 MINUTE')) . '+00:00'
        ];
        
        $response = $this->makeRequest($this->checkout_url, 'POST', $body);
        if ($response['status'] === 201 && isset($response['data']->id)) {
            $checkout_id = $response['data']->id;
            
            // Definir tipo de pagamento como PIX
            $pix_code = $this->setPaymentTypePix($checkout_id);
            
            return [
                'checkout_id' => $checkout_id,
                'pix_code' => $pix_code,
                'response' => json_encode($response['data'])
            ];
        }
        
        return false;
    }
    
    /**
     * Define tipo de pagamento como PIX e retorna código
     */
    private function setPaymentTypePix($checkout_id) {
        $url = $this->checkout_url . $checkout_id;
        $body = ['payment_type' => 'pix'];
        
        $response = $this->makeRequest($url, 'PUT', $body);
        
        if ($response['status'] === 200 && isset($response['data']->pix->artefacts)) {
            foreach ($response['data']->pix->artefacts as $artefact) {
                if ($artefact->name === 'code') {
                    return $artefact->content;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Cria checkout para cartao
     *
     * CORRECAO 1: valor enviado como INTEGER em centavos (SumUp exige integer, nao string)
     *   Antes: number_format($valor, 2, '', '') -> retornava STRING '200'
     *   Agora:  intval(round(floatval($valor) * 100)) -> retorna INTEGER 200
     *
     * CORRECAO 2: retorna array com erro detalhado (READER_OFFLINE, READER_BUSY, etc.)
     *   ao inves de simplesmente false, permitindo mensagem especifica ao usuario
     */
    public function createCheckoutCard($order_data, $reader_id, $card_type = 'credit') {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/checkout';
        
        // Converter valor para INTEGER em centavos
        $valor_centavos = intval(round(floatval($order_data['valor']) * 100));
        
        // CORRECAO 3: Remover campo 'installments' do payload
        // O endpoint /readers/{id}/checkout da SumUp NAO aceita 'installments'
        // (retorna HTTP 422: installments not allowed). O parcelamento e
        // gerenciado diretamente pelo leitor fisico SumUp Solo.
        $body = [
            'total_amount' => [
                'value'      => $valor_centavos,
                'currency'   => 'BRL',
                'minor_unit' => 2
            ],
            'description'  => $order_data['descricao'],
            'card_type'    => $card_type,
            'return_url'   => SITE_URL . '/api/webhook.php'
        ];
        
        $response = $this->makeRequest($url, 'POST', $body);
        
        if ($response['status'] === 201 && isset($response['data']->data->client_transaction_id)) {
            return [
                'checkout_id' => $response['data']->data->client_transaction_id,
                'response'    => json_encode($response['data'])
            ];
        }
        
        // Retornar erro detalhado da SumUp ao inves de false
        // A SumUp pode retornar dois formatos de erro:
        // 1. Erros de negocio: {"errors":{"type":"READER_OFFLINE","detail":"..."}}
        // 2. Erros de validacao: {"errors":{"campo":["mensagem"]}}
        $errors_obj   = $response['data']->errors ?? null;
        $error_type   = 'UNKNOWN_ERROR';
        $error_detail = 'Erro desconhecido';
        if ($errors_obj) {
            if (isset($errors_obj->type)) {
                // Formato 1: erro de negocio
                $error_type   = $errors_obj->type;
                $error_detail = $errors_obj->detail ?? 'Erro desconhecido';
            } else {
                // Formato 2: erro de validacao (ex: installments not allowed)
                $fields = array_keys((array) $errors_obj);
                $first_field = $fields[0] ?? 'campo';
                $first_msgs  = (array) ($errors_obj->$first_field ?? []);
                $error_type   = 'VALIDATION_ERROR';
                $error_detail = "Campo '{$first_field}': " . ($first_msgs[0] ?? 'invalido');
            }
        }
        
        $error_messages = [
            'READER_OFFLINE'   => 'Leitor de cartao esta desligado ou sem conexao. Verifique se o SumUp Solo esta ligado e conectado.',
            'READER_BUSY'      => 'Leitor de cartao esta ocupado com outra transacao. Aguarde ou cancele a transacao anterior.',
            'READER_NOT_FOUND' => 'Leitor de cartao nao encontrado. Verifique a configuracao da TAP no painel administrativo.',
            'UNAUTHORIZED'     => 'Token SumUp invalido ou expirado. Verifique as configuracoes de pagamento.',
        ];
        
        $error_msg_pt = $error_messages[$error_type] ?? $error_detail;
        
        Logger::error("SumUp createCheckoutCard - Falha", [
            'error_type'   => $error_type,
            'error_detail' => $error_detail,
            'reader_id'    => $reader_id,
            'status_http'  => $response['status'],
            'raw_response' => $response['raw_response']
        ]);
        
        return [
            'success'      => false,
            'error_type'   => $error_type,
            'error_detail' => $error_detail,
            'error_msg_pt' => $error_msg_pt,
            'raw_response' => $response['raw_response'],
            'curl_error'   => $response['curl_error']
        ];
    }
    
    /**
     * Cancela transação de cartão
     */
    public function cancelCardTransaction($reader_id) {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/terminate';
        $response = $this->makeRequest($url, 'POST', []);
        
        return $response['status'] === 202;
    }
    
    /**
     * Cancela checkout PIX
     */
    public function cancelPixTransaction($checkout_id) {
        $url = $this->checkout_url . $checkout_id;
        $response = $this->makeRequest($url, 'DELETE', null);
        
        return $response['status'] === 200;
    }
    
    /**
     * Faz requisição HTTP para API SumUp
     */
    private function makeRequest($url, $method, $data) {
        $ch = curl_init();
        
        $headers = [
            'Authorization: ' . $this->token,
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // CORRECAO: timeout para evitar travamento quando reader esta offline
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        // LOG: Requisição
        Logger::info("SumUp Request", [
            'url' => $url,
            'method' => $method,
            'data' => $data
        ]);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // LOG: Resposta
        Logger::info("SumUp Response", [
            'status' => $status,
            'response' => $response,
            'curl_error' => $curl_error
        ]);
        
        // Verificar erro de cURL
        if ($curl_error) {
            Logger::error("SumUp cURL Error", ['error' => $curl_error]);
        }
        
        return [
            'status' => $status,
            'data' => json_decode($response),
            'raw_response' => $response,
            'curl_error' => $curl_error
        ];
    }
}

/**
 * Gera QR Code em base64
 */
function generateQRCode($data) {
    // Usando API externa para gerar QR Code
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($data);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $image_data = curl_exec($ch);
    curl_close($ch);
    
    return base64_encode($image_data);
}
