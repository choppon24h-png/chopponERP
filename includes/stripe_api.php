<?php
/**
 * StripeAPI.php
 * Versão BLINDADA contra registros duplicados e chave errada
 */

class StripeAPI
{
    private PDO $pdo;

    private int $estabelecimento_id;
    private string $public_key;
    private string $secret_key;
    private ?string $webhook_secret;
    private string $modo;

    public function __construct(int $estabelecimento_id)
    {
        if (!function_exists('getDBConnection')) {
            throw new Exception('Função getDBConnection() não encontrada');
        }

        $this->pdo = getDBConnection();
        $this->estabelecimento_id = $estabelecimento_id;

        $this->log('INFO', 'Inicializando StripeAPI', [
            'estabelecimento_id' => $estabelecimento_id
        ]);

        $this->carregarConfiguracao();
    }

    /**
     * 🔒 CARREGAMENTO BLINDADO DA CONFIGURAÇÃO
     * Sempre pega o registro ATIVO mais recente
     */
    private function carregarConfiguracao(): void
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    stripe_public_key,
                    stripe_secret_key,
                    stripe_webhook_secret,
                    modo
                FROM stripe_config
                WHERE estabelecimento_id = ?
                  AND ativo = 1
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$this->estabelecimento_id]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                throw new Exception('Configuração do Stripe não encontrada para este estabelecimento');
            }

            $this->public_key     = trim($config['stripe_public_key'] ?? '');
            $this->secret_key     = trim($config['stripe_secret_key'] ?? '');
            $this->webhook_secret = $config['stripe_webhook_secret'] ?? null;
            $this->modo           = $config['modo'] ?? 'test';

            // 🔐 BLINDAGENS CRÍTICAS
            if (empty($this->secret_key)) {
                throw new Exception('STRIPE_SECRET_KEY não configurada');
            }

            if (!str_starts_with($this->secret_key, 'sk_')) {
                throw new Exception('STRIPE_SECRET_KEY inválida');
            }

            $this->log('INFO', 'Configuração Stripe carregada com sucesso', [
                'modo'    => $this->modo,
                'registro'=> 'mais_recente',
                'webhook' => $this->webhook_secret ? 'configurado' : 'não_configurado'
            ]);

        } catch (Throwable $e) {
            $this->log('ERROR', 'Erro ao carregar configuração Stripe', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /* ===================== GETTERS ===================== */

    public function getPublicKey(): string
    {
        return $this->public_key;
    }

    public function getSecretKey(): string
    {
        return $this->secret_key;
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    /* ===================== STRIPE ===================== */

    /**
     * Cria PaymentIntent (Stripe padrão moderno)
     */
    public function criarPaymentIntent(float $valor, array $metadata = []): array
    {
        $amount = (int) round($valor * 100); // centavos

        if ($amount <= 0) {
            throw new Exception('Valor inválido para pagamento');
        }

        $payload = [
            'amount' => $amount,
            'currency' => 'brl',
            'payment_method_types[]' => 'card',
            'metadata' => $metadata
        ];

        return $this->requestStripe(
            'POST',
            '/v1/payment_intents',
            $payload
        );
    }

    /**
     * Confirma PaymentIntent
     */
    public function confirmarPaymentIntent(string $paymentIntentId): array
    {
        return $this->requestStripe(
            'POST',
            "/v1/payment_intents/{$paymentIntentId}/confirm"
        );
    }

    /**
     * Requisição HTTP Stripe (cURL)
     */
    private function requestStripe(string $method, string $endpoint, array $data = []): array
    {
        $url = 'https://api.stripe.com' . $endpoint;

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $data ? http_build_query($data) : null,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->secret_key
            ]
        ]);

        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new Exception('Erro CURL Stripe: ' . curl_error($ch));
        }

        curl_close($ch);

        $json = json_decode($response, true);

        if ($http >= 400) {
            $this->log('ERROR', 'Erro Stripe API', [
                'http' => $http,
                'response' => $json
            ]);

            $msg = $json['error']['message'] ?? 'Erro desconhecido Stripe';
            throw new Exception($msg);
        }

        return $json;
    }

    /* ===================== CHECKOUT SESSION ===================== */

    /**
     * Criar Checkout Session (Stripe Hosted Payment Page)
     * Usado para royalties — redireciona o estabelecimento para pagar.
     *
     * @param array $params {
     *   amount       int    valor em centavos
     *   currency     string 'brl'
     *   description  string descrição
     *   success_url  string URL de retorno em caso de sucesso
     *   cancel_url   string URL de retorno em caso de cancelamento
     *   metadata     array  metadados (royalty_id, etc.)
     * }
     * @return array { id, url }
     */
    public function createCheckoutSession(array $params): array
    {
        $amount      = (int)($params['amount'] ?? 0);
        $currency    = $params['currency'] ?? 'brl';
        $description = $params['description'] ?? 'Royalty';
        $success_url = $params['success_url'] ?? SITE_URL . '/admin/royalty_pagamento_sucesso.php';
        $cancel_url  = $params['cancel_url']  ?? SITE_URL . '/admin/financeiro_royalties.php';
        $metadata    = $params['metadata']    ?? [];

        $data = [
            'payment_method_types[0]'          => 'card',
            'line_items[0][price_data][currency]'                      => $currency,
            'line_items[0][price_data][unit_amount]'                   => $amount,
            'line_items[0][price_data][product_data][name]'            => $description,
            'line_items[0][quantity]'                                  => 1,
            'mode'                             => 'payment',
            'success_url'                      => $success_url,
            'cancel_url'                       => $cancel_url,
        ];

        // Adicionar metadados
        foreach ($metadata as $k => $v) {
            $data["metadata[{$k}]"] = $v;
        }

        $session = $this->requestStripe('POST', '/v1/checkout/sessions', $data);

        if (empty($session['id']) || empty($session['url'])) {
            throw new Exception('Stripe não retornou session válida: ' . json_encode($session));
        }

        $this->log('INFO', 'Checkout Session criada', [
            'session_id' => $session['id'],
            'url'        => $session['url'],
        ]);

        return [
            'id'  => $session['id'],
            'url' => $session['url'],
        ];
    }

    /* ===================== WEBHOOK ===================== */

    /**
     * Validação de Webhook (opcional)
     */
    public function validarWebhook(string $payload, string $sigHeader): bool
    {
        if (empty($this->webhook_secret)) {
            // webhook não configurado → ignora validação
            return true;
        }

        if (!class_exists('\Stripe\Webhook')) {
            throw new Exception('Stripe SDK não carregado');
        }

        \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            $this->webhook_secret
        );

        return true;
    }

    /* ===================== LOG ===================== */

    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('appLog')) {
            appLog($level, $message, $context);
        }
    }
}
