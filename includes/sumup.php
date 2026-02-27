<?php
/**
 * Integracao com SumUp
 * v2.2 - timeout reduzido de 30s para 20s
 *      - getReaderStatus aceita state=IDLE+Wi-Fi como ONLINE (SumUp Solo comportamento real)
 *      - affiliate_key/app_id com fallback para constantes do config.php
 *      - loadPaymentConfig: prioridade config.php > banco de dados
 */

class SumUpIntegration {
    private $token;
    private $merchant_code;
    private $checkout_url;
    private $merchant_url;
    private $affiliate_key;
    private $affiliate_app_id;

    public function __construct() {
        $this->token         = 'Bearer ' . SUMUP_TOKEN;
        $this->merchant_code = SUMUP_MERCHANT_CODE;
        $this->checkout_url  = SUMUP_CHECKOUT_URL;
        $this->merchant_url  = SUMUP_MERCHANT_URL . $this->merchant_code;
        // Fallback primário: constantes do config.php (sempre disponíveis)
        $this->affiliate_key    = defined('SUMUP_AFFILIATE_KEY')    ? SUMUP_AFFILIATE_KEY    : '';
        $this->affiliate_app_id = defined('SUMUP_AFFILIATE_APP_ID') ? SUMUP_AFFILIATE_APP_ID : '';

        $this->loadPaymentConfig();
    }

    /**
     * Carrega configuracoes de pagamento salvas no banco.
     * Prioridade: token do banco > token em constante.
     */
    private function loadPaymentConfig(): void {
        try {
            $conn = getDBConnection();
            $cfg = null;

            try {
                $stmt = $conn->query("SELECT affiliate_key, affiliate_app_id, token_sumup FROM payment LIMIT 1");
                $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Fallback para bases sem a coluna affiliate_app_id
                $stmt = $conn->query("SELECT affiliate_key, token_sumup FROM payment LIMIT 1");
                $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$cfg) {
                return;
            }

            if (!empty($cfg['token_sumup']) && $cfg['token_sumup'] !== SUMUP_TOKEN) {
                $this->token = 'Bearer ' . $cfg['token_sumup'];
            }

            $this->affiliate_key    = trim((string) ($cfg['affiliate_key'] ?? ''));
            $this->affiliate_app_id = trim((string) ($cfg['affiliate_app_id'] ?? ''));
        } catch (Exception $e) {
            Logger::error('SumUp: erro ao carregar configuracoes de pagamento', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Adiciona uma leitora de cartao
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
     * Define tipo de pagamento como PIX e retorna codigo
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
     * Cria checkout para cartao via Cloud API (SumUp Solo)
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

        // Cloud API: affiliate e obrigatorio para rastreio da transacao
        if (!empty($this->affiliate_key)) {
            $affiliate = [
                'key' => $this->affiliate_key,
                'foreign_transaction_id' => 'ORDER-' . ($order_data['id'] ?? uniqid('', true)),
            ];

            if (!empty($this->affiliate_app_id)) {
                $affiliate['app_id'] = $this->affiliate_app_id;
            } else {
                Logger::warning('SumUp createCheckoutCard: affiliate_app_id nao configurado', [
                    'reader_id' => $reader_id,
                ]);
            }

            $body['affiliate'] = $affiliate;
        } else {
            Logger::warning(
                'SumUp createCheckoutCard: affiliate_key nao configurada. Configure em Pagamentos -> Affiliate Key.',
                ['reader_id' => $reader_id]
            );
        }

        $response = $this->makeRequest($url, 'POST', $body);

        if ($response['status'] === 201 && isset($response['data']->data->client_transaction_id)) {
            return [
                'checkout_id' => $response['data']->data->client_transaction_id,
                'response'    => json_encode($response['data']),
            ];
        }

        // Erro detalhado da SumUp
        $errors_obj   = $response['data']->errors ?? null;
        $error_type   = 'UNKNOWN_ERROR';
        $error_detail = 'Erro desconhecido';

        if ($errors_obj) {
            if (isset($errors_obj->type)) {
                $error_type   = $errors_obj->type;
                $error_detail = $errors_obj->detail ?? 'Erro desconhecido';
            } else {
                $fields       = array_keys((array) $errors_obj);
                $first_field  = $fields[0] ?? 'campo';
                $first_msgs   = (array) ($errors_obj->$first_field ?? []);
                $error_type   = 'VALIDATION_ERROR';
                $error_detail = "Campo '{$first_field}': " . ($first_msgs[0] ?? 'invalido');
            }
        }

        $error_messages = [
            'READER_OFFLINE'         => 'Leitor de cartao esta desligado ou sem conexao. No SumUp Solo: Connections -> API -> Connect.',
            'READER_BUSY'            => 'Leitor de cartao esta ocupado com outra transacao.',
            'READER_NOT_FOUND'       => 'Leitor de cartao nao encontrado.',
            'UNAUTHORIZED'           => 'Token SumUp invalido ou expirado.',
            'AFFILIATE_KEY_INVALID'  => 'Affiliate Key invalida.',
            'AFFILIATE_APP_ID_INVALID' => 'Affiliate App ID invalido.',
        ];

        $error_msg_pt = $error_messages[$error_type] ?? $error_detail;

        Logger::error('SumUp createCheckoutCard - falha', [
            'error_type'       => $error_type,
            'error_detail'     => $error_detail,
            'reader_id'        => $reader_id,
            'status_http'      => $response['status'],
            'affiliate_key'    => !empty($this->affiliate_key) ? 'configurada' : 'nao configurada',
            'affiliate_app_id' => !empty($this->affiliate_app_id) ? 'configurada' : 'nao configurada',
            'raw_response'     => $response['raw_response'],
        ]);

        Logger::payment('SumUp checkout cartao falhou', [
            'error_type'       => $error_type,
            'error_detail'     => $error_detail,
            'reader_id'        => $reader_id,
            'status_http'      => $response['status'],
            'has_affiliate'    => !empty($this->affiliate_key),
            'has_app_id'       => !empty($this->affiliate_app_id),
            'curl_error'       => $response['curl_error'],
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
     * Busca informacoes do reader (nome, serial) via GET /readers/{id}
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
     */
    public function getReaderStatus($reader_id): array {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/status';
        $response = $this->makeRequest($url, 'GET', null);

        if ($response['status'] === 200 && isset($response['data'])) {
            $data        = $response['data'];
            $status_data = $data->data ?? $data;
            $raw_status  = strtoupper($status_data->status ?? 'OFFLINE');
            $raw_state   = strtoupper($status_data->state ?? '');
            $connection  = $status_data->connection_type ?? null;

            // Aceita ONLINE explícito OU state=IDLE com conexão ativa
            // (SumUp Solo retorna status=OFFLINE mas state=IDLE quando pronto)
            $is_ready = ($raw_status === 'ONLINE')
                     || ($raw_state === 'IDLE'       && !empty($connection))
                     || ($raw_state === 'READY'      && !empty($connection))
                     || ($raw_state === 'PROCESSING');

            return [
                'online'        => $is_ready,
                'status_label'  => $is_ready ? 'ONLINE' : $raw_status,
                'state'         => $raw_state,
                'battery'       => $status_data->battery_level ?? null,
                'connection'    => $connection,
                'firmware'      => $status_data->firmware_version ?? null,
                'last_activity' => $status_data->last_activity ?? null,
            ];
        }

        return [
            'online'        => false,
            'status_label'  => 'OFFLINE',
            'state'         => null,
            'battery'       => null,
            'connection'    => null,
            'firmware'      => null,
            'last_activity' => null,
        ];
    }

    /**
     * Cancela transacao de cartao
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
     * Faz requisicao HTTP para API SumUp
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);  // Reduzido de 30s para 20s

        if ($data !== null && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        Logger::info('SumUp Request', ['url' => $url, 'method' => $method, 'data' => $data]);
        Logger::payment('SumUp request', ['url' => $url, 'method' => $method, 'has_body' => $data !== null]);

        $response   = curl_exec($ch);
        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        Logger::info('SumUp Response', ['status' => $status, 'response' => $response, 'curl_error' => $curl_error]);
        Logger::payment('SumUp response', ['status' => $status, 'curl_error' => $curl_error]);

        if ($curl_error) {
            Logger::error('SumUp cURL Error', ['error' => $curl_error]);
            Logger::payment('SumUp cURL error', ['error' => $curl_error, 'url' => $url]);
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
