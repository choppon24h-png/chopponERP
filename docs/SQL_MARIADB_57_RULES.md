# Padrões e Regras SQL — ChopponERP (MariaDB 5.7)

Este documento estabelece as regras obrigatórias para a criação e modificação de tabelas, colunas e queries no banco de dados do ChopponERP. O servidor de produção utiliza **MariaDB 5.7** (equivalente ao MySQL 5.7), o que impõe restrições específicas de compatibilidade.

Qualquer novo script SQL (`.sql`) ou alteração estrutural deve seguir estritamente as regras abaixo.

---

## 1. Tipos de Dados Restritos

### ❌ Proibido: Tipo `JSON`
O MariaDB 5.7 **não suporta** o tipo de dado nativo `JSON`.
- **Regra:** Sempre utilize `LONGTEXT` para armazenar payloads, configurações ou arrays JSON.
- **Exemplo Incorreto:** `payload JSON NULL`
- **Exemplo Correto:** `payload LONGTEXT NULL COMMENT 'Dados em formato JSON'`

### ❌ Proibido: Expressões em `DEFAULT`
O MariaDB 5.7 **não suporta** funções ou expressões na cláusula `DEFAULT` (exceto `CURRENT_TIMESTAMP` para datas).
- **Regra:** Não utilize `DEFAULT (UUID())`, `DEFAULT (NOW() + INTERVAL 1 DAY)`, etc.
- **Exemplo Incorreto:** `token VARCHAR(36) DEFAULT (UUID())`
- **Exemplo Correto:** Gere o UUID no backend (PHP) e insira via query, ou deixe `NULL` e atualize.

---

## 2. Padrões Obrigatórios de Tabela

Toda nova tabela criada no sistema deve obrigatoriamente conter a seguinte estrutura base:

### Engine e Charset
Todas as tabelas devem usar a engine `InnoDB` e o charset `utf8mb4` para suportar emojis e caracteres especiais corretamente.

```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Colunas de Auditoria (Timestamps)
Toda tabela principal deve conter as colunas `created_at` e `updated_at` com gerenciamento automático pelo banco de dados:

```sql
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

---

## 3. Chaves Primárias e Estrangeiras

### Chaves Primárias (IDs)
- Utilize `INT(11) NOT NULL AUTO_INCREMENT` para tabelas de volume normal.
- Utilize `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` para tabelas de alto volume (logs, movimentações, transações).

### Chaves Estrangeiras (FKs)
- O tipo de dado da FK deve ser **exatamente igual** ao da PK referenciada (ex: se a PK é `BIGINT UNSIGNED`, a FK também deve ser `BIGINT UNSIGNED`).
- Utilize `ON DELETE CASCADE` apenas quando a exclusão do registro pai dever obrigatoriamente apagar os filhos (ex: itens de um pedido).
- Utilize `ON DELETE SET NULL` ou `RESTRICT` para dados sensíveis (ex: histórico financeiro).

---

## 4. Sintaxe e Boas Práticas

### Criação Segura
Sempre utilize `IF NOT EXISTS` ao criar tabelas para evitar erros em execuções repetidas de scripts de migração.

```sql
CREATE TABLE IF NOT EXISTS `nome_da_tabela` ( ... );
```

### Comentários
Sempre adicione comentários (`COMMENT`) em colunas que armazenam ENUMs, JSONs (em LONGTEXT) ou status complexos, para facilitar o entendimento sem precisar consultar o código PHP.

```sql
`status` ENUM('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente' COMMENT 'pendente = aguardando pgto; pago = confirmado',
`config_data` LONGTEXT NULL COMMENT 'Configurações do gateway em formato JSON'
```

### Nomenclatura
- **Tabelas:** snake_case, no plural (ex: `estoque_pedidos`, `royalties`).
- **Colunas:** snake_case, no singular (ex: `valor_faturamento_bruto`, `data_vencimento`).
- **Tabelas de Ligação:** `tabela1_tabela2` (ex: `estabelecimento_usuarios`).

---

## 5. Exemplo de Tabela Padrão

```sql
CREATE TABLE IF NOT EXISTS `exemplo_padrao` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `estabelecimento_id` BIGINT UNSIGNED NULL COMMENT 'FK para estabelecimentos',
  `nome` VARCHAR(255) NOT NULL,
  `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `payload_dados` LONGTEXT NULL COMMENT 'Armazena JSON com dados extras',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estabelecimento` (`estabelecimento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
