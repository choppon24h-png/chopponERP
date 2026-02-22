<?php
/**
 * Integração com SumUp
 * v2.0 — Correção: affiliate_key obrigatório no Cloud API checkout
 *         Diagnóstico detalhado de READER_OFFLINE
 */

class SumUpIntegration {
    private $token;
    private $merchant_code;
    private $checkout_url;
    private $merchant_url;
    private $affiliate_key;

    public function __construct() {
        $this->token         = 'Bearer ' . SUMUP_TOKEN;
        $this->merchant_code = SUMUP_MERCHANT_CODE;
        $this->checkout_url  = SUMUP_CHECKOUT_URL;
        $this->merchant_url  = SUMUP_MERCHANT_URL . $this->merchant_code;

        // Carregar affiliate_key do banco de dados
        // A SumUp exige este campo em todas as requisições de checkout via Cloud API
        $this->affiliate_key = $this->loadAffiliateKey();
    }

    /**
     * Carrega a affiliate_key salva no banco de dados
     * Sem esta chave, os checkouts via Cloud API são rejeitados pela SumUp
     */
    private function loadAffiliateKey(): string {
        try {
            $conn = getDBConnection();
            $stmt = $conn->query("SELECT affiliate_key, token_sumup FROM payment LIMIT 1");
            $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($cfg['affiliate_key'])) {
                return $cfg['affiliate_key'];
            }
            // Se o token do banco for diferente da constante, atualiza o token também
            if (!empty($cfg['token_sumup']) && $cfg['token_sumup'] !== SUMUP_TOKEN) {
                $this->token = 'Bearer ' . $cfg['token_sumup'];
            }
        } catch (Exception $e) {
            Logger::error("SumUp: Erro ao carregar affiliate_key", ['error' => $e->getMessage()]);
        }
        return '';
    }

    /**
     * Adiciona uma leitora de cartão
     */
    public function addReader($pairing_code, $name = null) {
        $url  = $this->merchant_url . '/readers';
        $data = [
            'pairing_code' => strtoupper(trim($pairing_code)),
            'name'         => $name ?? "{$pairing_code}RE",
        ];

        $response = $this->makeRequest($url, 'POST', $data);

        if (($response['status'] === 201 || $response['status'] === 200) && isset($response['data']->id)) {
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
            'amount'             => $order_data['valor'],
            'currency'           => 'BRL',
            'merchant_code'      => $this->merchant_code,
            'description'        => $order_data['descricao'],
            'return_url'         => SITE_URL . '/api/webhook.php',
            'date'               => gmdate('Y-m-d\TH:i:s') . '+00:00',
            'valid_until'        => date('Y-m-d\TH:i:s', strtotime(gmdate('Y-m-d H:i:s') . ' +2 MINUTE')) . '+00:00',
        ];

        $response = $this->makeRequest($this->checkout_url, 'POST', $body);
        if ($response['status'] === 201 && isset($response['data']->id)) {
            $checkout_id = $response['data']->id;
            $pix_code    = $this->setPaymentTypePix($checkout_id);

            return [
                'checkout_id' => $checkout_id,
                'pix_code'    => $pix_code,
                'response'    => json_encode($response['data']),
            ];
        }

        return false;
    }

    /**
     * Define tipo de pagamento como PIX e retorna código
     */
    private function setPaymentTypePix($checkout_id) {
        $url  = $this->checkout_url . $checkout_id;
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
     * Cria checkout para cartão via Cloud API (SumUp Solo)
     *
     * CORREÇÃO PRINCIPAL: Envio do campo 'affiliate' obrigatório.
     * A SumUp Cloud API exige o affiliate_key em todas as transações.
     * Sem ele, o checkout pode ser rejeitado com erro de validação.
     *
     * CORREÇÃO 1: valor enviado como INTEGER em centavos
     * CORREÇÃO 2: retorna array com erro detalhado (READER_OFFLINE, READER_BUSY, etc.)
     * CORREÇÃO 3: campo 'installments' removido (não aceito neste endpoint)
     */
    public function createCheckoutCard($order_data, $reader_id, $card_type = 'credit') {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/checkout';

        // Converter valor para INTEGER em centavos
        $valor_centavos = intval(round(floatval($order_data['valor']) * 100));

        $body = [
            'total_amount' => [
                'value'      => $valor_centavos,
                'currency'   => 'BRL',
                'minor_unit' => 2,
            ],
            'description' => $order_data['descricao'],
            'card_type'   => $card_type,
            'return_url'  => SITE_URL . '/api/webhook.php',
        ];

        // ─── AFFILIATE KEY — obrigatório para Cloud API ───────────────
        // A SumUp requer este campo para rastrear a origem da transação.
        // Sem ele, o checkout pode ser rejeitado.
        if (!empty($this->affiliate_key)) {
            $body['affiliate'] = ['key' => $this->affiliate_key];
        } else {
            Logger::warning("SumUp createCheckoutCard: affiliate_key não configurada. " .
                "Configure em Pagamentos → Affiliate Key para evitar rejeições.", [
                'reader_id' => $reader_id,
            ]);
        }

        $response = $this->makeRequest($url, 'POST', $body);

        if ($response['status'] === 201 && isset($response['data']->data->client_transaction_id)) {
            return [
                'checkout_id' => $response['data']->data->client_transaction_id,
                'response'    => json_encode($response['data']),
            ];
        }

        // Retornar erro detalhado da SumUp
        // Formato 1 — erro de negócio: {"errors":{"type":"READER_OFFLINE","detail":"..."}}
        // Formato 2 — erro de validação: {"errors":{"campo":["mensagem"]}}
        $errors_obj   = $response['data']->errors ?? null;
        $error_type   = 'UNKNOWN_ERROR';
        $error_detail = 'Erro desconhecido';

        if ($errors_obj) {
            if (isset($errors_obj->type)) {
                $error_type   = $errors_obj->type;
                $error_detail = $errors_obj->detail ?? 'Erro desconhecido';
            } else {
                $fields      = array_keys((array) $errors_obj);
                $first_field = $fields[0] ?? 'campo';
                $first_msgs  = (array) ($errors_obj->$first_field ?? []);
                $error_type   = 'VALIDATION_ERROR';
                $error_detail = "Campo '{$first_field}': " . ($first_msgs[0] ?? 'inválido');
            }
        }

        $error_messages = [
            'READER_OFFLINE'   => 'Leitor de cartão está desligado ou sem conexão. ' .
                                  'No SumUp Solo: Connections → API → Connect. ' .
                                  'O dispositivo deve exibir "Connected — Ready to transact".',
            'READER_BUSY'      => 'Leitor de cartão está ocupado com outra transação. Aguarde ou cancele a transação anterior.',
            'READER_NOT_FOUND' => 'Leitor de cartão não encontrado. Verifique a configuração da TAP no painel administrativo.',
            'UNAUTHORIZED'     => 'Token SumUp inválido ou expirado. Verifique as configurações de pagamento.',
            'AFFILIATE_KEY_INVALID' => 'Affiliate Key inválida. Configure em Pagamentos → Affiliate Key.',
        ];

        $error_msg_pt = $error_messages[$error_type] ?? $error_detail;

        Logger::error("SumUp createCheckoutCard - Falha", [
            'error_type'      => $error_type,
            'error_detail'    => $error_detail,
            'reader_id'       => $reader_id,
            'status_http'     => $response['status'],
            'affiliate_key'   => !empty($this->affiliate_key) ? 'configurada' : 'NÃO CONFIGURADA',
            'raw_response'    => $response['raw_response'],
        ]);

        return [
            'success'      => false,
            'error_type'   => $error_type,
            'error_detail' => $error_detail,
            'error_msg_pt' => $error_msg_pt,
            'raw_response' => $response['raw_response'],
            'curl_error'   => $response['curl_error'],
        ];
    }

    /**
     * Busca informações do reader (nome, serial) via GET /readers/{id}
     */
    public function getReaderInfo($reader_id) {
        $url      = $this->merchant_url . '/readers/' . $reader_id;
        $response = $this->makeRequest($url, 'GET', null);
        if ($response['status'] === 200 && isset($response['data'])) {
            $data = $response['data'];
            return [
                'reader_id' => $reader_id,
                'name'      => $data->name ?? null,
                'serial'    => $data->device->identifier ?? null,
                'status'    => $data->status ?? null,
            ];
        }
        return null;
    }

    /**
     * Busca status de conectividade do reader (ONLINE/OFFLINE)
     * Nota: retorna o ÚLTIMO STATUS CONHECIDO, não em tempo real.
     * Para estar ONLINE, o dispositivo deve estar ligado e com
     * Connections → API → Connect ativo.
     */
    public function getReaderStatus($reader_id): array {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/status';
        $response = $this->makeRequest($url, 'GET', null);

        if ($response['status'] === 200 && isset($response['data'])) {
            $data = $response['data'];
            // A resposta pode estar em data->data ou direto em data
            $status_data = $data->data ?? $data;
            $raw_status  = strtoupper($status_data->status ?? 'OFFLINE');
            return [
                'online'        => $raw_status === 'ONLINE',
                'status_label'  => $raw_status,
                'battery'       => $status_data->battery_level ?? null,
                'connection'    => $status_data->connection_type ?? null,
                'firmware'      => $status_data->firmware_version ?? null,
                'last_activity' => $status_data->last_activity ?? null,
            ];
        }

        return [
            'online'        => false,
            'status_label'  => 'OFFLINE',
            'battery'       => null,
            'connection'    => null,
            'firmware'      => null,
            'last_activity' => null,
        ];
    }

    /**
     * Cancela transação de cartão
     */
    public function cancelCardTransaction($reader_id) {
        $url      = $this->merchant_url . '/readers/' . $reader_id . '/terminate';
        $response = $this->makeRequest($url, 'POST', []);
        return $response['status'] === 202;
    }

    /**
     * Cancela checkout PIX
     */
    public function cancelPixTransaction($checkout_id) {
        $url      = $this->checkout_url . $checkout_id;
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
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($data !== null && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        Logger::info("SumUp Request", ['url' => $url, 'method' => $method, 'data' => $data]);

        $response   = curl_exec($ch);
        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        Logger::info("SumUp Response", ['status' => $status, 'response' => $response, 'curl_error' => $curl_error]);

        if ($curl_error) {
            Logger::error("SumUp cURL Error", ['error' => $curl_error]);
        }

        return [
            'status'       => $status,
            'data'         => json_decode($response),
            'raw_response' => $response,
            'curl_error'   => $curl_error,
        ];
    }
}

/**
 * Gera QR Code em base64
 */
function generateQRCode($data) {
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($data);
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $image_data = curl_exec($ch);
    curl_close($ch);
    return base64_encode($image_data);
}
