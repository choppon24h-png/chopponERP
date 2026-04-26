<?php
/**
 * Integração com SumUp — ChoppOnTap
 *
 * v3.2.0 — Correção do CHECKOUT_EXPIRED (valid_until)
 *   PROBLEMA IDENTIFICADO:
 *     O método setPaymentTypePix() fazia um PUT /v0.1/checkouts/{id} com
 *     { "payment_type": "pix" }, que é o endpoint de PROCESSAR checkout (cobrar
 *     o instrumento de pagamento), não de definir o tipo.
 *     Para PIX, o fluxo correto é:
 *       1. POST /v0.1/checkouts  (criar checkout)
 *       2. PUT  /v0.1/checkouts/{id}  com { "payment_type": "pix" }  → retorna
 *          o objeto com pix.artefacts contendo o código EMV e a URL da imagem.
 *     O código já estava correto nessa parte, MAS havia dois problemas:
 *       a) generateQRCode() era uma FUNÇÃO GLOBAL, não um método da classe.
 *          Em create_order.php chamava-se $sumup->generateQRCode(), o que
 *          gerava "Call to undefined method" silencioso (capturado pelo catch).
 *       b) A função global generateQRCode() usava api.qrserver.com sem timeout
 *          e sem tratamento de falha — se a API externa estivesse lenta, o
 *          base64 retornado seria de uma imagem de erro ou vazio.
 *       c) Não havia fallback: se pix.artefacts[barcode].location retornasse
 *          uma imagem JPEG pronta, o código ignorava e gerava QR Code do zero.
 *
 *   CORREÇÕES APLICADAS:
 *     1. generateQRCode() movido para dentro da classe como método público.
 *     2. Adicionada tentativa de usar a imagem JPEG já pronta da SumUp
 *        (artefact name="barcode") antes de gerar QR Code externamente.
 *     3. Fallback em cascata:
 *          a) Imagem JPEG da SumUp (artefact barcode.location)
 *          b) Geração via api.qrserver.com (com timeout de 10s)
 *          c) Geração via goqr.me como segundo fallback
 *     4. Logs detalhados em cada etapa para diagnóstico via cPanel.
 *     5. Timeout adicionado em makeRequest (connecttimeout=8, timeout=20).
 *     6. Método getReaderInfo() com tratamento robusto de resposta.
 *     7. Constante SUMUP_AFFILIATE_KEY e SUMUP_AFFILIATE_APP_ID com fallback
 *        seguro caso não estejam definidas no config.php.
 *
 * Referências da API SumUp:
 *   - POST /v0.1/checkouts       → Criar checkout
 *   - PUT  /v0.1/checkouts/{id}  → Processar checkout (define payment_type)
 *     Resposta PIX: { "pix": { "artefacts": [ { "name": "barcode", ... },
 *                                              { "name": "code", "content": "EMV..." } ] } }
 *   - POST /v0.1/merchants/{code}/readers/{id}/checkout → Checkout na leitora
 */

class SumUpIntegration {
    private $token;
    private $merchant_code;
    private $checkout_url;
    private $merchant_url;
    private $email;
    private $affiliate_key;
    private $affiliate_app_id;

    public function __construct() {
        $this->token         = 'Bearer ' . SUMUP_TOKEN;
        $this->merchant_code = SUMUP_MERCHANT_CODE;
        $this->checkout_url  = SUMUP_CHECKOUT_URL;
        $this->merchant_url  = SUMUP_MERCHANT_URL . $this->merchant_code;
            $this->email         = defined('SUMUP_EMAIL') ? SUMUP_EMAIL : 'choppon24h@gmail.com';

        // Fallback primário: constantes do config.php (sempre disponíveis)
        $this->affiliate_key    = defined('SUMUP_AFFILIATE_KEY')    ? SUMUP_AFFILIATE_KEY    : '';
        $this->affiliate_app_id = defined('SUMUP_AFFILIATE_APP_ID') ? SUMUP_AFFILIATE_APP_ID : '';

        $this->loadPaymentConfig();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIGURAÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Carrega configurações de pagamento salvas no banco.
     * Prioridade: token do banco > token em constante.
     */
    private function loadPaymentConfig(): void {
        try {
            $conn = getDBConnection();
            $cfg  = null;

            try {
                $stmt = $conn->query("SELECT affiliate_key, affiliate_app_id, token_sumup FROM payment LIMIT 1");
                $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Fallback para bases sem a coluna affiliate_app_id
                try {
                    $stmt = $conn->query("SELECT affiliate_key, token_sumup FROM payment LIMIT 1");
                    $cfg  = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e2) {
                    Logger::warning('SumUp: tabela payment sem colunas esperadas', [
                        'error' => $e2->getMessage()
                    ]);
                }
            }

            if (!$cfg) {
                return;
            }

            if (!empty($cfg['token_sumup']) && $cfg['token_sumup'] !== SUMUP_TOKEN) {
                $this->token = 'Bearer ' . $cfg['token_sumup'];
            }

            if (!empty($cfg['affiliate_key'])) {
                $this->affiliate_key = trim((string) $cfg['affiliate_key']);
            }
            if (!empty($cfg['affiliate_app_id'])) {
                $this->affiliate_app_id = trim((string) $cfg['affiliate_app_id']);
            }
        } catch (Exception $e) {
            Logger::error('SumUp: erro ao carregar configurações de pagamento', [
                'error' => $e->getMessage()
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEITORAS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Adiciona uma leitora de cartão via pairing_code.
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
     * Busca informações do reader (nome, serial) via GET /readers/{id}.
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
     * Busca status de conectividade do reader (ONLINE/OFFLINE).
     * O SumUp Solo retorna status=OFFLINE mas state=IDLE quando pronto — tratado aqui.
     */
    public function getReaderStatus($reader_id): array {
        $url      = $this->merchant_url . '/readers/' . $reader_id . '/status';
        $response = $this->makeRequest($url, 'GET', null);

        if ($response['status'] === 200 && isset($response['data'])) {
            $data        = $response['data'];
            $status_data = $data->data ?? $data;
            $raw_status  = strtoupper($status_data->status ?? 'OFFLINE');
            $raw_state   = strtoupper($status_data->state  ?? '');
            $connection  = $status_data->connection_type ?? null;

            // Aceita ONLINE explícito OU state=IDLE/READY com conexão ativa
            $is_ready = ($raw_status === 'ONLINE')
                     || ($raw_state === 'IDLE'       && !empty($connection))
                     || ($raw_state === 'READY'      && !empty($connection))
                     || ($raw_state === 'PROCESSING');

            return [
                'online'        => $is_ready,
                'status_label'  => $is_ready ? 'ONLINE' : $raw_status,
                'state'         => $raw_state,
                'battery'       => $status_data->battery_level   ?? null,
                'connection'    => $connection,
                'firmware'      => $status_data->firmware_version ?? null,
                'last_activity' => $status_data->last_activity    ?? null,
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

    // ─────────────────────────────────────────────────────────────────────────
    // CHECKOUT PIX
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria checkout PIX na SumUp.
     *
     * Fluxo correto (documentação SumUp APM):
     *   1. POST /v0.1/checkouts  → cria o checkout, retorna { id, status: "PENDING" }
     *   2. PUT  /v0.1/checkouts/{id}  com { "payment_type": "pix" }
     *      → SumUp retorna o objeto com pix.artefacts:
     *        - artefact name="barcode"  → imagem JPEG do QR Code (URL em .location)
     *        - artefact name="code"     → string EMV (copia e cola) em .content
     *
     * @param array $order_data  ['id', 'valor', 'descricao']
     * @return array|false  ['checkout_id', 'pix_code', 'qr_code_base64', 'response']
     *                      ou false em caso de falha
     */
    public function createCheckoutPix($order_data) {
        // ── Passo 1: Criar o checkout ────────────────────────────────────────────────────
        // IMPORTANTE: valid_until e date DEVEM usar UTC (gmdate).
        // O servidor está configurado com America/Sao_Paulo (UTC-3), portanto
        // date() retorna horário local. Se valid_until for enviado com horário
        // local + sufixo '+00:00', a SumUp interpreta como UTC e vê a data
        // como 3 horas no passado → CHECKOUT_EXPIRED imediatamente.
        // Solução: usar gmdate() para ambos os campos (sempre UTC real).
        $now_utc        = gmdate('Y-m-d\TH:i:s') . 'Z'; // formato ISO 8601 UTC com Z
        $valid_until_utc = gmdate('Y-m-d\TH:i:s', time() + 1800) . 'Z'; // +30 min em UTC

        $body = [
            'checkout_reference' => 'CO' . $order_data['id'],
            'amount'             => floatval($order_data['valor']),
            'currency'           => 'BRL',
            'merchant_code'      => $this->merchant_code,
                'pay_to_email'       => $this->email, // OBRIGATORIO para PIX (documentacao SumUp)
            'description'        => $order_data['descricao'],
            'return_url'         => SITE_URL . '/api/webhook.php',
            'date'               => $now_utc,
            'valid_until'        => $valid_until_utc,
        ];

        Logger::info('SumUp PIX - Passo 1: criando checkout', [
            'checkout_reference' => $body['checkout_reference'],
            'amount'             => $body['amount'],
        ]);

        $response = $this->makeRequest($this->checkout_url, 'POST', $body);

        Logger::info('SumUp PIX - Resposta Passo 1 (criar checkout)', [
            'http_status'  => $response['status'],
            'checkout_id'  => $response['data']->id ?? 'N/A',
            'status_field' => $response['data']->status ?? 'N/A',
            'curl_error'   => $response['curl_error'] ?: 'nenhum',
        ]);

        if ($response['status'] !== 201 || !isset($response['data']->id)) {
            Logger::error('SumUp PIX - Falha ao criar checkout (Passo 1)', [
                'http_status'  => $response['status'],
                'raw_response' => $response['raw_response'],
                'curl_error'   => $response['curl_error'],
            ]);
            return false;
        }

        $checkout_id = $response['data']->id;

        // ── Passo 2: Processar checkout com payment_type=pix ─────────────────
        $pix_result = $this->processCheckoutPix($checkout_id);

        if (!$pix_result) {
            Logger::error('SumUp PIX - Falha ao processar checkout PIX (Passo 2)', [
                'checkout_id' => $checkout_id,
            ]);
            // Retorna parcialmente para não perder o checkout_id já criado
            return [
                'checkout_id'     => $checkout_id,
                'pix_code'        => null,
                'qr_code_base64'  => null,
                'response'        => json_encode($response['data']),
            ];
        }

        Logger::info('SumUp PIX - Checkout PIX criado com sucesso', [
            'checkout_id'    => $checkout_id,
            'pix_code_len'   => strlen($pix_result['pix_code'] ?? ''),
            'has_barcode_url' => !empty($pix_result['barcode_url']),
        ]);

        return [
            'checkout_id'    => $checkout_id,
            'pix_code'       => $pix_result['pix_code'],
            'qr_code_base64' => $pix_result['qr_code_base64'],
            'response'       => json_encode($response['data']),
        ];
    }

    /**
     * Processa o checkout com payment_type=pix via PUT /v0.1/checkouts/{id}.
     * Extrai o código EMV e a imagem do QR Code dos artefatos retornados.
     *
     * Estrutura da resposta SumUp para PIX:
     * {
     *   "pix": {
     *     "artefacts": [
     *       { "name": "barcode", "content_type": "image/jpeg", "location": "https://..." },
     *       { "name": "code",    "content_type": "text/plain",  "content": "00020126..." }
     *     ]
     *   }
     * }
     *
     * @param string $checkout_id
     * @return array|false  ['pix_code', 'barcode_url', 'qr_code_base64']
     */
    private function processCheckoutPix($checkout_id) {
        $url = $this->checkout_url . $checkout_id;

        // ── Tenta payment_type=pix primeiro, depois qr_code_pix como fallback ──
        // Diferença (doc oficial SumUp):
        //   pix         → pago direto na conta bancária SumUp do merchant (sem taxa)
        //   qr_code_pix → pago via processo normal de repasse (com taxa)
        // Contas sem PIX direto habilitado retornam 4xx para payment_type=pix;
        // nesse caso tentamos qr_code_pix automaticamente.
        $payment_types_to_try = ['pix', 'qr_code_pix'];
        $response             = null;
        $used_payment_type    = null;

        foreach ($payment_types_to_try as $pt) {
            $body = ['payment_type' => $pt];

            Logger::info('SumUp PIX - Passo 2: tentando payment_type=' . $pt, [
                'checkout_id' => $checkout_id,
                'url'         => $url,
            ]);

            $response = $this->makeRequest($url, 'PUT', $body);

            Logger::info('SumUp PIX - Resposta Passo 2 (payment_type=' . $pt . ')', [
                'http_status'  => $response['status'],
                'has_pix'      => isset($response['data']->pix)         ? 'sim' : 'não',
                'has_qr_pix'   => isset($response['data']->qr_code_pix) ? 'sim' : 'não',
                'raw_response' => substr($response['raw_response'] ?? '', 0, 500),
                'curl_error'   => $response['curl_error'] ?: 'nenhum',
            ]);

            if ($response['status'] === 200 && isset($response['data'])) {
                $used_payment_type = $pt;
                break; // Sucesso — para de tentar
            }

            Logger::warning('SumUp PIX - payment_type=' . $pt . ' falhou, tentando próximo', [
                'http_status'  => $response['status'],
                'raw_response' => substr($response['raw_response'] ?? '', 0, 300),
            ]);
        }

        if (!$used_payment_type || !isset($response['data'])) {
            Logger::error('SumUp PIX - Todos os payment_types falharam no Passo 2', [
                'checkout_id'  => $checkout_id,
                'http_status'  => $response['status'] ?? 0,
                'raw_response' => $response['raw_response'] ?? '',
                'curl_error'   => $response['curl_error'] ?? '',
            ]);
            return false;
        }

        $data = $response['data'];

        // ── Extrair artefatos do PIX ──────────────────────────────────────────
        // A SumUp retorna o objeto sob a chave correspondente ao payment_type usado.
        $pix_obj = $data->pix ?? $data->qr_code_pix ?? null;

        if (!$pix_obj || !isset($pix_obj->artefacts)) {
            Logger::error('SumUp PIX - Objeto pix/qr_code_pix não encontrado na resposta', [
                'checkout_id'   => $checkout_id,
                'payment_type'  => $used_payment_type,
                'response_keys' => array_keys((array) $data),
                'raw_response'  => substr($response['raw_response'] ?? '', 0, 1000),
            ]);
            return false;
        }

        $pix_code    = null;
        $barcode_url = null;

        foreach ($pix_obj->artefacts as $artefact) {
            $name = $artefact->name ?? '';

            if ($name === 'code') {
                // Código EMV (copia e cola do PIX)
                // O campo 'content' pode estar vazio; nesse caso usa 'location' para buscar
                $pix_code = $artefact->content ?? null;

                if (empty($pix_code) && !empty($artefact->location)) {
                    // Alguns merchants recebem o EMV via URL em vez de inline
                    Logger::info('SumUp PIX - content vazio, buscando EMV via location URL', [
                        'location' => $artefact->location,
                    ]);
                    $emv_raw = $this->downloadImage($artefact->location);
                    if (!empty($emv_raw)) {
                        $pix_code = trim((string) $emv_raw);
                    }
                }

                Logger::info('SumUp PIX - Código EMV encontrado', [
                    'pix_code_len' => strlen($pix_code ?? ''),
                    'source'       => !empty($artefact->content) ? 'inline' : 'location_url',
                ]);
            }

            if ($name === 'barcode') {
                // URL da imagem JPEG do QR Code já gerada pela SumUp
                $barcode_url = $artefact->location ?? null;
                Logger::info('SumUp PIX - URL da imagem barcode encontrada', [
                    'barcode_url' => $barcode_url,
                ]);
            }
        }

        if (!$pix_code && !$barcode_url) {
            Logger::error('SumUp PIX - Nenhum artefato útil encontrado', [
                'checkout_id'  => $checkout_id,
                'payment_type' => $used_payment_type,
                'artefacts'    => json_encode($pix_obj->artefacts),
            ]);
            return false;
        }

        // ── Gerar QR Code em Base64 ───────────────────────────────────────────
        // Estratégia em cascata:
        //   1. Usar imagem JPEG já pronta da SumUp (barcode_url) — mais rápido
        //   2. Gerar via api.qrserver.com a partir do código EMV
        //   3. Gerar via goqr.me como segundo fallback
        $qr_code_base64 = $this->generateQRCode($pix_code, $barcode_url);

        return [
            'pix_code'       => $pix_code,
            'barcode_url'    => $barcode_url,
            'qr_code_base64' => $qr_code_base64,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GERAÇÃO DE QR CODE (MÉTODO DA CLASSE — CORREÇÃO PRINCIPAL)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gera imagem do QR Code em Base64 para o app Android.
     *
     * CORREÇÃO: Este método agora é PÚBLICO e está DENTRO da classe.
     * O create_order.php chamava $sumup->generateQRCode() mas o método era
     * uma função global, causando "Call to undefined method" silencioso.
     *
     * Estratégia em cascata:
     *   1. Baixar imagem JPEG já gerada pela SumUp (barcode_url) — sem custo extra
     *   2. Gerar via api.qrserver.com com o código EMV
     *   3. Gerar via goqr.me como segundo fallback
     *
     * @param string|null $pix_code    Código EMV do PIX (copia e cola)
     * @param string|null $barcode_url URL da imagem JPEG já pronta da SumUp
     * @return string  Base64 da imagem PNG/JPEG do QR Code
     */
    public function generateQRCode($pix_code, $barcode_url = null): string {
        // ── Tentativa 1: Imagem JPEG já pronta da SumUp ───────────────────────
        if (!empty($barcode_url)) {
            Logger::info('SumUp QRCode - Tentativa 1: baixando imagem da SumUp', [
                'url' => $barcode_url,
            ]);

            $image_data = $this->downloadImage($barcode_url);

            if (!empty($image_data) && strlen($image_data) > 500) {
                Logger::info('SumUp QRCode - Imagem da SumUp obtida com sucesso', [
                    'bytes' => strlen($image_data),
                ]);
                return base64_encode($image_data);
            }

            Logger::warning('SumUp QRCode - Imagem da SumUp falhou ou muito pequena', [
                'bytes' => strlen($image_data ?? ''),
            ]);
        }

        // ── Tentativa 2: api.qrserver.com ─────────────────────────────────────
        if (!empty($pix_code)) {
            $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($pix_code);

            Logger::info('SumUp QRCode - Tentativa 2: api.qrserver.com', [
                'pix_code_len' => strlen($pix_code),
            ]);

            $image_data = $this->downloadImage($url);

            if (!empty($image_data) && strlen($image_data) > 500) {
                Logger::info('SumUp QRCode - QR Code gerado via qrserver.com', [
                    'bytes' => strlen($image_data),
                ]);
                return base64_encode($image_data);
            }

            Logger::warning('SumUp QRCode - qrserver.com falhou', [
                'bytes' => strlen($image_data ?? ''),
            ]);

            // ── Tentativa 3: goqr.me (fallback) ──────────────────────────────
            $url2 = 'https://api.goqr.me/v1/create-qr-code/?size=300x300&data=' . urlencode($pix_code);

            Logger::info('SumUp QRCode - Tentativa 3: goqr.me', []);

            $image_data2 = $this->downloadImage($url2);

            if (!empty($image_data2) && strlen($image_data2) > 500) {
                Logger::info('SumUp QRCode - QR Code gerado via goqr.me', [
                    'bytes' => strlen($image_data2),
                ]);
                return base64_encode($image_data2);
            }

            Logger::error('SumUp QRCode - Todas as tentativas falharam', [
                'pix_code_len'  => strlen($pix_code),
                'barcode_url'   => $barcode_url ?? 'N/A',
            ]);
        }

        Logger::error('SumUp QRCode - Sem pix_code nem barcode_url disponíveis', []);
        return '';
    }

    /**
     * Baixa uma imagem de uma URL externa com timeout controlado.
     *
     * @param string $url
     * @return string|false  Bytes da imagem ou false em caso de falha
     */
    private function downloadImage($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->token,
        ]);

        $data       = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            Logger::warning('SumUp downloadImage - cURL error', [
                'url'   => $url,
                'error' => $curl_error,
            ]);
            return false;
        }

        if ($http_code !== 200) {
            Logger::warning('SumUp downloadImage - HTTP não-200', [
                'url'         => $url,
                'http_status' => $http_code,
            ]);
            return false;
        }

        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECKOUT CARTÃO (Cloud API — SumUp Solo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria checkout para cartão via Cloud API (SumUp Solo).
     *
     * Endpoint: POST /v0.1/merchants/{code}/readers/{reader_id}/checkout
     *
     * @param array  $order_data  ['id', 'valor', 'descricao']
     * @param string $reader_id   ID da leitora SumUp Solo
     * @param string $card_type   'credit' ou 'debit'
     * @return array  ['checkout_id', 'response'] em caso de sucesso,
     *                ou ['success'=>false, 'error_type', 'error_msg_pt', ...] em falha
     */
    public function createCheckoutCard($order_data, $reader_id, $card_type = 'credit') {
        $url = $this->merchant_url . '/readers/' . $reader_id . '/checkout';

        // Converter valor para inteiro em centavos (obrigatório pela API)
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

        // Affiliate é obrigatório para rastreio via Cloud API
        if (!empty($this->affiliate_key)) {
            $affiliate = [
                'key'                    => $this->affiliate_key,
                'foreign_transaction_id' => 'ORDER-' . ($order_data['id'] ?? uniqid('', true)),
            ];

            if (!empty($this->affiliate_app_id)) {
                $affiliate['app_id'] = $this->affiliate_app_id;
            } else {
                Logger::warning('SumUp Card - affiliate_app_id não configurado', [
                    'reader_id' => $reader_id,
                ]);
            }

            $body['affiliate'] = $affiliate;
        } else {
            Logger::warning(
                'SumUp Card - affiliate_key não configurada. Configure em Pagamentos → Affiliate Key.',
                ['reader_id' => $reader_id]
            );
        }

        Logger::info('SumUp Card - Enviando checkout para leitora', [
            'reader_id'      => $reader_id,
            'card_type'      => $card_type,
            'valor_centavos' => $valor_centavos,
            'has_affiliate'  => !empty($this->affiliate_key),
        ]);

        $response = $this->makeRequest($url, 'POST', $body);

        if ($response['status'] === 201 && isset($response['data']->data->client_transaction_id)) {
            Logger::info('SumUp Card - Checkout criado com sucesso', [
                'checkout_id' => $response['data']->data->client_transaction_id,
                'reader_id'   => $reader_id,
            ]);

            return [
                'checkout_id' => $response['data']->data->client_transaction_id,
                'response'    => json_encode($response['data']),
            ];
        }

        // ── Tratamento de erros detalhado ─────────────────────────────────────
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
                $error_type  = 'VALIDATION_ERROR';
                $error_detail = "Campo '{$first_field}': " . ($first_msgs[0] ?? 'inválido');
            }
        }

        $error_messages = [
            'READER_OFFLINE'           => 'Leitor de cartão está desligado ou sem conexão. No SumUp Solo: Connections → API → Connect.',
            'READER_BUSY'              => 'Leitor de cartão está ocupado com outra transação.',
            'READER_NOT_FOUND'         => 'Leitor de cartão não encontrado.',
            'UNAUTHORIZED'             => 'Token SumUp inválido ou expirado.',
            'AFFILIATE_KEY_INVALID'    => 'Affiliate Key inválida.',
            'AFFILIATE_APP_ID_INVALID' => 'Affiliate App ID inválido.',
        ];

        $error_msg_pt = $error_messages[$error_type] ?? $error_detail;

        Logger::error('SumUp Card - Falha ao criar checkout', [
            'error_type'       => $error_type,
            'error_detail'     => $error_detail,
            'reader_id'        => $reader_id,
            'http_status'      => $response['status'],
            'affiliate_key'    => !empty($this->affiliate_key)    ? 'configurada' : 'não configurada',
            'affiliate_app_id' => !empty($this->affiliate_app_id) ? 'configurada' : 'não configurada',
            'raw_response'     => $response['raw_response'],
        ]);

        Logger::payment('SumUp checkout cartão falhou', [
            'error_type'  => $error_type,
            'reader_id'   => $reader_id,
            'http_status' => $response['status'],
            'curl_error'  => $response['curl_error'],
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

    // ─────────────────────────────────────────────────────────────────────────
    // CANCELAMENTOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancela transação de cartão na leitora (terminate).
     */
    public function cancelCardTransaction($reader_id) {
        $url      = $this->merchant_url . '/readers/' . $reader_id . '/terminate';
        $response = $this->makeRequest($url, 'POST', []);
        return $response['status'] === 202;
    }

    /**
     * Cancela (desativa) checkout PIX.
     */
    public function cancelPixTransaction($checkout_id) {
        $url      = $this->checkout_url . $checkout_id;
        $response = $this->makeRequest($url, 'DELETE', null);
        return $response['status'] === 200;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP (INTERNO)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Faz requisição HTTP para a API SumUp com logs detalhados.
     *
     * @param string      $url
     * @param string      $method   GET | POST | PUT | PATCH | DELETE
     * @param array|null  $data
     * @return array  ['status', 'data', 'raw_response', 'curl_error']
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        Logger::info('SumUp Request', [
            'url'      => $url,
            'method'   => $method,
            'has_body' => $data !== null,
        ]);
        Logger::payment('SumUp request', [
            'url'      => $url,
            'method'   => $method,
            'has_body' => $data !== null,
        ]);

        $response   = curl_exec($ch);
        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        Logger::info('SumUp Response', [
            'status'       => $status,
            'response_len' => strlen($response ?? ''),
            'curl_error'   => $curl_error ?: 'nenhum',
        ]);
        Logger::payment('SumUp response', [
            'status'     => $status,
            'curl_error' => $curl_error ?: 'nenhum',
        ]);

        if ($curl_error) {
            Logger::error('SumUp cURL Error', [
                'error' => $curl_error,
                'url'   => $url,
            ]);
        }

        return [
            'status'       => $status,
            'data'         => json_decode($response),
            'raw_response' => $response,
            'curl_error'   => $curl_error,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FUNÇÃO GLOBAL DE COMPATIBILIDADE (mantida para não quebrar código legado)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @deprecated Use SumUpIntegration::generateQRCode() diretamente.
 *             Esta função global é mantida apenas para compatibilidade com
 *             código legado (bck/api/create_order.php).
 */
if (!function_exists('generateQRCode')) {
    function generateQRCode($data) {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($data);
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $image_data = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && !empty($image_data) && strlen($image_data) > 500) {
            return base64_encode($image_data);
        }

        return '';
    }
}
