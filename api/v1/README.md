# API v1 — ChoppOn App Mobile

Esta pasta contém os endpoints da API REST versionada para o aplicativo mobile Flutter ChoppOn.

## Estrutura

| Endpoint | Arquivo | Método | Descrição |
|---|---|---|---|
| `/api/v1/auth/login.php` | `auth/login.php` | POST | Login de cliente (CPF + senha) |
| `/api/v1/auth/admin_login.php` | `auth/admin_login.php` | POST | Login admin/técnico (email + senha) |
| `/api/v1/clientes/profile.php` | `clientes/profile.php` | GET | Perfil do cliente autenticado |
| `/api/v1/pontos/dashboard.php` | `pontos/dashboard.php` | GET | Saldo e últimos consumos |
| `/api/v1/extrato/consumo.php` | `extrato/consumo.php` | GET | Histórico paginado de consumo |
| `/api/v1/ranking/nacional.php` | `ranking/nacional.php` | GET | Top 10 ranking nacional |

## Autenticação

Todos os endpoints protegidos exigem o header `Authorization: Bearer {token}` ou `Token: {token}`.

## Pré-requisito de Banco de Dados

Antes de usar esta API, execute o script de migração:

```sql
-- Localizado em: sql/app_mobile_migration.sql
```

Este script adiciona os campos `password` e `fcm_token` na tabela `clientes`.
