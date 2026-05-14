<?php
/**
 * PaymentConfigManager — ChoppOnTap
 *
 * Gerencia credenciais de pagamento (SumUp e Mercado Pago) por estabelecimento.
 * Busca na tabela `payment_config` (multi-estabelecimento) com fallback para
 * as constantes globais definidas em config.php.
 *
 * Uso:
 *   $cfg = PaymentConfigManager::getConfig($estabelecimento_id);
 *   $token = $cfg['sumup_token'];
 *
 * @version 1.0.0
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
        // Normaliza null para 0 (chave de cache para fallback global)
        $cache_key = (int) $estabelecimento_id;

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $config = self::buildDefault();

        if ($estabelecimento_id) {
            $config = self::loadFromDB($estabelecimento_id, $config);
        }

        self::$cache[$cache_key] = $config;
        return $config;
    }

    /**
     * Invalida o cache para um estabelecimento específico (útil após salvar).
     *
     * @param int|null $estabelecimento_id
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
     * Constrói o array de configuração padrão usando constantes do config.php.
     */
    private static function buildDefault(): array {
        return [
            // SumUp
            'sumup_token'            => defined('SUMUP_TOKEN')            ? SUMUP_TOKEN            : '',
            'sumup_merchant_code'    => defined('SUMUP_MERCHANT_CODE')    ? SUMUP_MERCHANT_CODE    : '',
            'sumup_affiliate_key'    => defined('SUMUP_AFFILIATE_KEY')    ? SUMUP_AFFILIATE_KEY    : '',
            'sumup_affiliate_app_id' => defined('SUMUP_AFFILIATE_APP_ID') ? SUMUP_AFFILIATE_APP_ID : '',
            'sumup_webhook_secret'   => defined('SUMUP_WEBHOOK_SECRET')   ? SUMUP_WEBHOOK_SECRET   : '',
            'sumup_email'            => defined('SUMUP_EMAIL')            ? SUMUP_EMAIL            : '',
            // Métodos habilitados (SumUp)
            'pix'    => 1,
            'credit' => 1,
            'debit'  => 1,
            // Mercado Pago
            'mp_access_token'  => defined('MP_ACCESS_TOKEN')  ? MP_ACCESS_TOKEN  : '',
            'mp_public_key'    => defined('MP_PUBLIC_KEY')    ? MP_PUBLIC_KEY    : '',
            'mp_ambiente'      => 'production',
            'mp_webhook_url'   => '',
            'mp_webhook_secret'=> '',
        ];
    }

    /**
     * Carrega configurações do banco de dados para o estabelecimento informado.
     * Sobrescreve apenas os campos que estiverem preenchidos no banco.
     *
     * @param int   $estabelecimento_id
     * @param array $defaults  Array de fallback (constantes do config.php)
     * @return array
     */
    private static function loadFromDB(int $estabelecimento_id, array $defaults): array {
        try {
            $conn = getDBConnection();

            // Verificar se a tabela payment_config existe
            $tbl_exists = self::tableExists($conn, 'payment_config');

            if ($tbl_exists) {
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
                    return self::mergeWithDefaults($row, $defaults);
                }

                // Nenhuma linha encontrada para este estabelecimento — retorna defaults
                if (class_exists('Logger')) {
                    Logger::warning('PaymentConfigManager: sem config para estabelecimento', [
                        'estabelecimento_id' => $estabelecimento_id,
                    ]);
                }
                return $defaults;
            }

            // Tabela payment_config não existe — fallback para tabela payment legada
            return self::loadFromLegacyPayment($conn, $estabelecimento_id, $defaults);

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
     * Fallback: carrega da tabela `payment` legada (antes da migração).
     * Mantém compatibilidade durante o período de transição.
     */
    private static function loadFromLegacyPayment(PDO $conn, int $estabelecimento_id, array $defaults): array {
        try {
            // Tenta buscar por estabelecimento_id primeiro
            $stmt = $conn->prepare(
                "SELECT token_sumup, affiliate_key, affiliate_app_id, merchant_code, pix, credit, debit
                 FROM payment
                 WHERE estabelecimento_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$estabelecimento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Se não encontrou por estabelecimento, pega o primeiro registro (comportamento legado)
            if (!$row) {
                $stmt = $conn->query("SELECT token_sumup, affiliate_key, affiliate_app_id, merchant_code, pix, credit, debit FROM payment LIMIT 1");
                $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$row) {
                return $defaults;
            }

            // Mapeia colunas legadas para o novo formato
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

            // Garante que a tabela existe
            if (!self::tableExists($conn, 'payment_config')) {
                if (class_exists('Logger')) {
                    Logger::error('PaymentConfigManager: tabela payment_config não existe — execute a migração SQL');
                }
                return false;
            }

            // Campos permitidos para salvar
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

            // Verificar se já existe registro para este estabelecimento
            $stmt = $conn->prepare("SELECT id FROM payment_config WHERE estabelecimento_id = ? LIMIT 1");
            $stmt->execute([$estabelecimento_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // UPDATE
                $set_clause = implode(', ', array_map(fn($f) => "`{$f}` = ?", $fields));
                $stmt = $conn->prepare(
                    "UPDATE payment_config SET {$set_clause}, updated_at = NOW()
                     WHERE estabelecimento_id = ?"
                );
                $values[] = $estabelecimento_id;
                $result = $stmt->execute($values);
            } else {
                // INSERT
                $fields[]  = 'estabelecimento_id';
                $values[]  = $estabelecimento_id;
                $col_list  = implode(', ', array_map(fn($f) => "`{$f}`", $fields));
                $ph_list   = implode(', ', array_fill(0, count($fields), '?'));
                $stmt = $conn->prepare(
                    "INSERT INTO payment_config ({$col_list}) VALUES ({$ph_list})"
                );
                $result = $stmt->execute($values);
            }

            // Invalida cache após salvar
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
