<?php
/**
 * PaymentConfigManager — ChoppOnTap
 * v1.1.0 — Correção do bug de credencial cruzada entre estabelecimentos
 *
 * Gerencia credenciais de pagamento (SumUp e Mercado Pago) por estabelecimento.
 *
 * Ordem de prioridade para busca de credenciais:
 *   1. Tabela payment_config (nova, multi-estabelecimento)
 *   2. Tabela mercadopago_config legada (por estabelecimento_id)
 *   3. Tabela payment legada (SumUp, por estabelecimento_id)
 *   4. Constantes globais do config.php (APENAS para campos SumUp, nunca MP)
 *
 * IMPORTANTE: Para Mercado Pago, NUNCA usar constantes globais como fallback
 * pois elas pertencem ao estabelecimento 1 (Chopp On Almeida). Se o estab 2
 * não tiver mp_access_token configurado, o sistema deve lançar erro explícito.
 *
 * Uso:
 *   $cfg = PaymentConfigManager::getConfig($estabelecimento_id);
 *   $token = $cfg['sumup_token'];
 *   $mp_token = $cfg['mp_access_token']; // vazio string se não configurado
 *
 * @version 1.1.0
 */
class PaymentConfigManager {

    /** Cache em memória para evitar múltiplas queries na mesma requisição */
    private static array $cache = [];

    /**
     * Retorna a configuração de pagamento para um estabelecimento.
     *
     * @param int|null $estabelecimento_id  ID do estabelecimento (null = usa fallback global)
     * @return array  Array com todas as chaves de configuração
     */
    public static function getConfig(?int $estabelecimento_id): array {
        $cache_key = (int) $estabelecimento_id;

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        // Defaults: SumUp usa constantes globais, MP começa VAZIO
        $config = self::buildDefault();

        if ($estabelecimento_id) {
            $config = self::loadFromDB($estabelecimento_id, $config);
        }

        self::$cache[$cache_key] = $config;
        return $config;
    }

    /**
     * Invalida o cache para um estabelecimento específico (útil após salvar).
     */
    public static function clearCache(?int $estabelecimento_id = null): void {
        if ($estabelecimento_id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[(int) $estabelecimento_id]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODOS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Constrói o array de configuração padrão.
     *
     * SumUp: usa constantes globais do config.php como base (estab 1 é o padrão).
     * Mercado Pago: começa VAZIO — nunca herdar token de outro estabelecimento.
     */
    private static function buildDefault(): array {
        return [
            // SumUp — constantes globais como base (sobrescritas pelo banco)
            'sumup_token'            => defined('SUMUP_TOKEN')            ? SUMUP_TOKEN            : '',
            'sumup_merchant_code'    => defined('SUMUP_MERCHANT_CODE')    ? SUMUP_MERCHANT_CODE    : '',
            'sumup_affiliate_key'    => defined('SUMUP_AFFILIATE_KEY')    ? SUMUP_AFFILIATE_KEY    : '',
            'sumup_affiliate_app_id' => defined('SUMUP_AFFILIATE_APP_ID') ? SUMUP_AFFILIATE_APP_ID : '',
            'sumup_webhook_secret'   => defined('SUMUP_WEBHOOK_SECRET')   ? SUMUP_WEBHOOK_SECRET   : '',
            'sumup_email'            => defined('SUMUP_EMAIL')            ? SUMUP_EMAIL            : '',
            // Métodos habilitados
            'pix'    => 1,
            'credit' => 1,
            'debit'  => 1,
            // Mercado Pago — SEMPRE começa vazio para forçar busca por estabelecimento
            // Nunca usar constante global como fallback de MP (pertence ao estab 1)
            'mp_access_token'   => '',
            'mp_public_key'     => '',
            'mp_ambiente'       => 'production',
            'mp_webhook_url'    => '',
            'mp_webhook_secret' => '',
        ];
    }

    /**
     * Carrega configurações do banco de dados para o estabelecimento informado.
     *
     * Ordem de busca:
     *   1. payment_config (nova tabela multi-estabelecimento)
     *   2. mercadopago_config (tabela legada de MP, por estabelecimento)
     *   3. payment (tabela legada de SumUp, por estabelecimento)
     */
    private static function loadFromDB(int $estabelecimento_id, array $defaults): array {
        try {
            $conn = getDBConnection();

            // ── 1. Tentar payment_config (nova tabela) ────────────────────────
            if (self::tableExists($conn, 'payment_config')) {
                $stmt = $conn->prepare(
                    "SELECT
                        sumup_token,
                        sumup_affiliate_key,
                        sumup_affiliate_app_id,
                        sumup_merchant_code,
                        sumup_webhook_secret,
                        pix,
                        credit,
                        debit,
                        mp_access_token,
                        mp_public_key,
                        mp_ambiente,
                        mp_webhook_url,
                        mp_webhook_secret
                     FROM payment_config
                     WHERE estabelecimento_id = ? AND status = 1
                     LIMIT 1"
                );
                $stmt->execute([$estabelecimento_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    if (class_exists('Logger')) {
                        Logger::info('PaymentConfigManager: config carregada via payment_config', [
                            'estabelecimento_id' => $estabelecimento_id,
                            'mp_token_present'   => !empty($row['mp_access_token']),
                            'sumup_token_present'=> !empty($row['sumup_token']),
                        ]);
                    }
                    $merged = self::mergeWithDefaults($row, $defaults);

                    // Se payment_config existe mas mp_access_token está vazio,
                    // tentar complementar com mercadopago_config legada
                    if (empty($merged['mp_access_token'])) {
                        $merged = self::complementMpFromLegacy($conn, $estabelecimento_id, $merged);
                    }
                    return $merged;
                }

                // Nenhuma linha na payment_config para este estabelecimento
                if (class_exists('Logger')) {
                    Logger::warning('PaymentConfigManager: sem linha na payment_config, tentando tabelas legadas', [
                        'estabelecimento_id' => $estabelecimento_id,
                    ]);
                }
            }

            // ── 2. Fallback: mercadopago_config + payment legadas ─────────────
            $config = self::loadFromMercadoPagoConfig($conn, $estabelecimento_id, $defaults);
            $config = self::loadFromLegacyPayment($conn, $estabelecimento_id, $config);
            return $config;

        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('PaymentConfigManager: erro ao carregar config', [
                    'estabelecimento_id' => $estabelecimento_id,
                    'error'              => $e->getMessage(),
                ]);
            }
            return $defaults;
        }
    }

    /**
     * Complementa mp_access_token a partir da tabela mercadopago_config legada.
     * Usado quando payment_config existe mas mp_access_token está vazio.
     */
    private static function complementMpFromLegacy(PDO $conn, int $estabelecimento_id, array $config): array {
        try {
            if (!self::tableExists($conn, 'mercadopago_config')) {
                return $config;
            }
            $stmt = $conn->prepare(
                "SELECT access_token, public_key, ambiente, webhook_url, webhook_secret
                 FROM mercadopago_config
                 WHERE estabelecimento_id = ? AND status = 1
                 LIMIT 1"
            );
            $stmt->execute([$estabelecimento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['access_token'])) {
                $config['mp_access_token']   = $row['access_token'];
                $config['mp_public_key']     = $row['public_key']     ?? $config['mp_public_key'];
                $config['mp_ambiente']       = $row['ambiente']        ?? $config['mp_ambiente'];
                $config['mp_webhook_url']    = $row['webhook_url']     ?? $config['mp_webhook_url'];
                $config['mp_webhook_secret'] = $row['webhook_secret']  ?? $config['mp_webhook_secret'];
                if (class_exists('Logger')) {
                    Logger::info('PaymentConfigManager: mp_access_token complementado via mercadopago_config', [
                        'estabelecimento_id' => $estabelecimento_id,
                    ]);
                }
            }
        } catch (Exception $e) { /* ignora */ }
        return $config;
    }

    /**
     * Carrega credenciais MP da tabela mercadopago_config por estabelecimento.
     * Nunca usa LIMIT sem WHERE de estabelecimento_id para evitar token cruzado.
     */
    private static function loadFromMercadoPagoConfig(PDO $conn, int $estabelecimento_id, array $defaults): array {
        try {
            if (!self::tableExists($conn, 'mercadopago_config')) {
                return $defaults;
            }
            $stmt = $conn->prepare(
                "SELECT access_token, public_key, ambiente, webhook_url, webhook_secret
                 FROM mercadopago_config
                 WHERE estabelecimento_id = ? AND status = 1
                 LIMIT 1"
            );
            $stmt->execute([$estabelecimento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['access_token'])) {
                $defaults['mp_access_token']   = $row['access_token'];
                $defaults['mp_public_key']     = $row['public_key']    ?? '';
                $defaults['mp_ambiente']       = $row['ambiente']       ?? 'production';
                $defaults['mp_webhook_url']    = $row['webhook_url']    ?? '';
                $defaults['mp_webhook_secret'] = $row['webhook_secret'] ?? '';
                if (class_exists('Logger')) {
                    Logger::info('PaymentConfigManager: config MP carregada via mercadopago_config', [
                        'estabelecimento_id' => $estabelecimento_id,
                    ]);
                }
            } else {
                // Sem config MP para este estabelecimento — mp_access_token fica vazio
                // NÃO herdar de outro estabelecimento
                if (class_exists('Logger')) {
                    Logger::warning('PaymentConfigManager: sem mp_access_token para estabelecimento', [
                        'estabelecimento_id' => $estabelecimento_id,
                    ]);
                }
            }
        } catch (Exception $e) { /* ignora */ }
        return $defaults;
    }

    /**
     * Fallback: carrega credenciais SumUp da tabela `payment` legada.
     * Busca SEMPRE por estabelecimento_id — nunca pega o primeiro registro genérico.
     */
    private static function loadFromLegacyPayment(PDO $conn, int $estabelecimento_id, array $defaults): array {
        try {
            if (!self::tableExists($conn, 'payment')) {
                return $defaults;
            }
            $stmt = $conn->prepare(
                "SELECT token_sumup, affiliate_key, affiliate_app_id, merchant_code, pix, credit, debit
                 FROM payment
                 WHERE estabelecimento_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$estabelecimento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Se não encontrou por estabelecimento E é o estab 1 (padrão legado),
            // pega o primeiro registro (compatibilidade com instalações antigas)
            if (!$row && $estabelecimento_id === 1) {
                $stmt = $conn->query(
                    "SELECT token_sumup, affiliate_key, affiliate_app_id, merchant_code, pix, credit, debit
                     FROM payment LIMIT 1"
                );
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$row) {
                return $defaults;
            }

            $mapped = [
                'sumup_token'            => !empty($row['token_sumup'])      ? $row['token_sumup']      : $defaults['sumup_token'],
                'sumup_affiliate_key'    => !empty($row['affiliate_key'])    ? $row['affiliate_key']    : $defaults['sumup_affiliate_key'],
                'sumup_affiliate_app_id' => !empty($row['affiliate_app_id']) ? $row['affiliate_app_id'] : $defaults['sumup_affiliate_app_id'],
                'sumup_merchant_code'    => !empty($row['merchant_code'])    ? $row['merchant_code']    : $defaults['sumup_merchant_code'],
                'pix'                    => isset($row['pix'])    ? (int) $row['pix']    : $defaults['pix'],
                'credit'                 => isset($row['credit']) ? (int) $row['credit'] : $defaults['credit'],
                'debit'                  => isset($row['debit'])  ? (int) $row['debit']  : $defaults['debit'],
            ];

            return array_merge($defaults, $mapped);

        } catch (Exception $e) {
            return $defaults;
        }
    }

    /**
     * Mescla os dados do banco com os defaults, sobrescrevendo apenas campos não-vazios.
     */
    private static function mergeWithDefaults(array $row, array $defaults): array {
        $map = [
            'sumup_token'            => 'sumup_token',
            'sumup_affiliate_key'    => 'sumup_affiliate_key',
            'sumup_affiliate_app_id' => 'sumup_affiliate_app_id',
            'sumup_merchant_code'    => 'sumup_merchant_code',
            'sumup_webhook_secret'   => 'sumup_webhook_secret',
            'pix'                    => 'pix',
            'credit'                 => 'credit',
            'debit'                  => 'debit',
            'mp_access_token'        => 'mp_access_token',
            'mp_public_key'          => 'mp_public_key',
            'mp_ambiente'            => 'mp_ambiente',
            'mp_webhook_url'         => 'mp_webhook_url',
            'mp_webhook_secret'      => 'mp_webhook_secret',
        ];

        $result = $defaults;
        foreach ($map as $db_col => $cfg_key) {
            if (isset($row[$db_col]) && $row[$db_col] !== null && $row[$db_col] !== '') {
                $result[$cfg_key] = $row[$db_col];
            }
        }
        return $result;
    }

    /**
     * Verifica se uma tabela existe no banco de dados atual.
     */
    private static function tableExists(PDO $conn, string $table): bool {
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Salva ou atualiza a configuração de pagamento de um estabelecimento.
     * Retorna true em sucesso, false em falha.
     *
     * @param int   $estabelecimento_id
     * @param array $data  Array com os campos a salvar (chaves = colunas da tabela)
     * @return bool
     */
    public static function saveConfig(int $estabelecimento_id, array $data): bool {
        try {
            $conn = getDBConnection();

            if (!self::tableExists($conn, 'payment_config')) {
                if (class_exists('Logger')) {
                    Logger::error('PaymentConfigManager: tabela payment_config não existe — execute a migração SQL');
                }
                return false;
            }

            $allowed = [
                'sumup_token', 'sumup_affiliate_key', 'sumup_affiliate_app_id',
                'sumup_merchant_code', 'sumup_webhook_secret',
                'pix', 'credit', 'debit',
                'mp_access_token', 'mp_public_key', 'mp_ambiente',
                'mp_webhook_url', 'mp_webhook_secret', 'status',
            ];

            $fields = [];
            $values = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = $field;
                    $values[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $stmt = $conn->prepare("SELECT id FROM payment_config WHERE estabelecimento_id = ? LIMIT 1");
            $stmt->execute([$estabelecimento_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $set_clause = implode(', ', array_map(fn($f) => "`{$f}` = ?", $fields));
                $stmt = $conn->prepare(
                    "UPDATE payment_config SET {$set_clause}, updated_at = NOW()
                     WHERE estabelecimento_id = ?"
                );
                $values[] = $estabelecimento_id;
                $result = $stmt->execute($values);
            } else {
                $fields[]  = 'estabelecimento_id';
                $values[]  = $estabelecimento_id;
                $col_list  = implode(', ', array_map(fn($f) => "`{$f}`", $fields));
                $ph_list   = implode(', ', array_fill(0, count($fields), '?'));
                $stmt = $conn->prepare(
                    "INSERT INTO payment_config ({$col_list}) VALUES ({$ph_list})"
                );
                $result = $stmt->execute($values);
            }

            self::clearCache($estabelecimento_id);
            return $result;

        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('PaymentConfigManager: erro ao salvar config', [
                    'estabelecimento_id' => $estabelecimento_id,
                    'error'              => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /**
     * Retorna todas as configurações de todos os estabelecimentos (para Admin Geral).
     *
     * @return array  Array indexado por estabelecimento_id
     */
    public static function getAllConfigs(): array {
        try {
            $conn = getDBConnection();
            if (!self::tableExists($conn, 'payment_config')) {
                return [];
            }
            $stmt = $conn->query(
                "SELECT pc.*, e.name AS estabelecimento_nome
                 FROM payment_config pc
                 LEFT JOIN estabelecimentos e ON e.id = pc.estabelecimento_id
                 WHERE pc.status = 1
                 ORDER BY e.name ASC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[$row['estabelecimento_id']] = $row;
            }
            return $result;
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::error('PaymentConfigManager: erro ao buscar todas as configs', [
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }
    }
}
