<?php
/**
 * MercadoPagoPix — PIX via Mercado Pago
 *
 * Usado em api/create_order.php como substituto do PIX SumUp
 * (SumUp descontinuou PIX pela API em 2025).
 *
 * Fluxo:
 *   POST https://api.mercadopago.com/v1/payments  com payment_method_id=pix
 *   Resposta 201: point_of_interaction.transaction_data com qr_code e qr_code_base64
 */
require_once __DIR__ . '/config.php';

class MercadoPagoPix {

    private $accessToken;
    private $webhookUrl;
    private $estabelecimentoId;

    public function __construct($estabelecimentoId) {
        $this->estabelecimentoId = (int) $estabelecimentoId;
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT access_token, webhook_url FROM mercadopago_config
             WHERE estabelecimento_id = ? AND status = 1 LIMIT 1"
        );
        $stmt->execute([$this->estabelecimentoId]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cfg) {
            throw new \RuntimeException(
                "MercadoPago nao configurado para estabelecimento_id={$this->estabelecimentoId}"
            );
        }

        $this->accessToken = $cfg['access_token'];
        $this->webhookUrl  = !empty($cfg['webhook_url'])
            ? $cfg['webhook_url']
            : 'https://ochoppoficial.com.br/webhooks/mercadopago_webhook.php';
    }

    /**
     * Cria pagamento PIX no Mercado Pago.
     *
     * @param array $order_data  ['id', 'valor', 'descricao', 'cpf', 'email'(opcional)]
     * @return array|false  ['payment_id', 'qr_code', 'qr_code_base64', 'ticket_url', 'status']
     */
    public function createPixPayment(array $order_data) {
        $cpf   = preg_replace('/\D/', '', $order_data['cpf'] ?? '');
        $email = !empty($order_data['email'])
            ? $order_data['email']
            : 'cpf' . $cpf . '@choppon.app';

        $payload = [
            'transaction_amount' => floatval($order_data['valor']),
            'description'        => $order_data['descricao'],
            'payment_method_id'  => 'pix',
            'notification_url'   => $this->webhookUrl,
            'external_reference' => 'CO' . $order_data['id'],
            'payer'              => [
                'email'          => $email,
                'first_name'     => 'Cliente',
                'last_name'      => 'ChoppOn',
                'identification' => [
                    'type'   => 'CPF',
                    'number' => $cpf,
                ],
            ],
        ];

        $idempotencyKey = 'CO' . $order_data['id'] . '-' . time();

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
                'X-Idempotency-Key: ' . $idempotencyKey,
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        Logger::payment('MercadoPago PIX - resposta', [
            'order_id'   => $order_data['id'],
            'http_code'  => $httpCode,
            'raw'        => substr($response ?? '', 0, 500),
            'curl_error' => $curlError,
        ]);

        if ($curlError) {
            Logger::error('MercadoPago PIX - erro cURL', [
                'order_id'   => $order_data['id'],
                'curl_error' => $curlError,
            ]);
            return false;
        }

        if ($httpCode !== 201 && $httpCode !== 200) {
            Logger::error('MercadoPago PIX - falhou', [
                'http_code' => $httpCode,
                'response'  => substr($response ?? '', 0, 500),
            ]);
            return false;
        }

        $data = json_decode($response);

        if (!$data || !isset($data->point_of_interaction->transaction_data->qr_code)) {
            Logger::error('MercadoPago PIX - qr_code ausente na resposta', [
                'response' => substr($response ?? '', 0, 500),
            ]);
            return false;
        }

        $txData = $data->point_of_interaction->transaction_data;

        return [
            'payment_id'     => $data->id,
            'qr_code'        => $txData->qr_code        ?? null,
            'qr_code_base64' => $txData->qr_code_base64 ?? null,
            'ticket_url'     => $txData->ticket_url      ?? null,
            'status'         => $data->status,
        ];
    }
}
