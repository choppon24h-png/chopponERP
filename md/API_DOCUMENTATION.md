# Documentação da API - Chopp On Tap

## Visão Geral

A API REST do sistema Chopp On Tap permite a integração com aplicativos Android que controlam as torneiras automáticas (TAPs) e gerenciam o fluxo de chopp.

**Base URL:** `https://seudominio.com.br/api/`

## Autenticação

A API utiliza **JWT (JSON Web Token)** para autenticação. Todas as requisições (exceto login) devem incluir o token no header:

```
Token: eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

## Endpoints

### 1. Login

**Endpoint:** `POST /api/login.php`

**Descrição:** Autentica usuário e retorna token JWT.

**Request Body:**
```json
{
  "email": "choppon24h@gmail.com",
  "password": "Admin259087@"
}
```

**Response Success (200):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "choppon24h@gmail.com",
    "type": 1
  },
  "isAdmin": true
}
```

**Response Error (401):**
```json
{
  "user": false,
  "error": "Credenciais inválidas"
}
```

---

### 2. Validar Token

**Endpoint:** `GET /api/validate_token.php`

**Headers:**
```
Token: <jwt_token>
```

**Response Success (200):**
```json
{
  "valid": {
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "choppon24h@gmail.com",
      "type": 1,
      "estabelecimentos": [1, 2]
    }
  }
}
```

**Response Error (401):**
```json
{
  "valid": false,
  "error": "Token inválido"
}
```

---

### 3. Verificar TAP

**Endpoint:** `POST /api/verify_tap.php`

**Descrição:** Retorna informações da bebida e TAP baseado no Android ID.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "android_id": "ABC123"
}
```

**Response Success (200):**
```json
{
  "image": "https://seudominio.com.br/uploads/bebidas/heineken.jpg",
  "preco": 5.00,
  "bebida": "Heineken",
  "volume": 45.5,
  "cartao": true
}
```

**Campos:**
- `image`: URL completa da imagem da bebida
- `preco`: Preço por 100ml
- `bebida`: Nome da bebida
- `volume`: Volume disponível na TAP (em litros)
- `cartao`: Se a TAP possui leitora de cartão configurada

**Response Error (404):**
```json
{
  "error": "TAP não encontrada"
}
```

---

### 4. Criar Pedido

**Endpoint:** `POST /api/create_order.php`

**Descrição:** Cria um novo pedido e inicia processo de pagamento.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "valor": 5.00,
  "descricao": "Heineken 100ml",
  "android_id": "ABC123",
  "payment_method": "pix",
  "quantidade": 100,
  "cpf": "12345678900"
}
```

**Campos:**
- `valor`: Valor total do pedido (float)
- `descricao`: Descrição do pedido
- `android_id`: ID do dispositivo Android
- `payment_method`: Método de pagamento (`pix`, `credit`, `debit`)
- `quantidade`: Quantidade em mililitros (int)
- `cpf`: CPF do cliente

**Response Success - PIX (200):**
```json
{
  "checkout_id": "abc-123-def-456",
  "qr_code": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
}
```

**Response Success - Cartão (200):**
```json
{
  "checkout_id": "abc-123-def-456"
}
```

**Response Error (400):**
```json
{
  "error": "TAP não possui leitora de cartão configurada"
}
```

---

### 5. Verificar Checkout

**Endpoint:** `POST /api/verify_checkout.php`

**Descrição:** Verifica se o pagamento foi aprovado.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "android_id": "ABC123",
  "checkout_id": "abc-123-def-456"
}
```

**Response Success (200):**
```json
{
  "status": "success"
}
```

**Response Pending (200):**
```json
{
  "status": "false"
}
```

---

### 6. Líquido Liberado

**Endpoint:** `POST /api/liquido_liberado.php`

**Descrição:** Atualiza o volume consumido da TAP após liberação.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "android_id": "ABC123",
  "qtd_ml": 100
}
```

**Campos:**
- `android_id`: ID do dispositivo Android
- `qtd_ml`: Quantidade liberada em centilitros (será convertido para litros dividindo por 100)

**Response Success (200):**
```json
[true]
```

---

### 7. Liberação Iniciada

**Endpoint:** `POST /api/liberacao.php?action=iniciada`

**Descrição:** Marca o início da liberação do líquido.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "checkout_id": "abc-123-def-456"
}
```

**Response Success (200):**
```json
{
  "success": true
}
```

---

### 8. Liberação Finalizada

**Endpoint:** `POST /api/liberacao.php?action=finalizada`

**Descrição:** Marca o fim da liberação e atualiza quantidade liberada.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "checkout_id": "abc-123-def-456",
  "qtd_ml": 100
}
```

**Response Success (200):**
```json
{
  "success": true,
  "status": "FINISHED"
}
```

**Status possíveis:**
- `PROCESSING`: Ainda liberando
- `FINISHED`: Liberação completa

---

### 9. Cancelar Pedido

**Endpoint:** `POST /api/cancel_order.php`

**Descrição:** Cancela um pedido e a transação na SumUp.

**Headers:**
```
Token: <jwt_token>
```

**Request Body:**
```json
{
  "checkout_id": "abc-123-def-456"
}
```

**Response Success (200):**
```json
{
  "success": true,
  "cancelled_at_sumup": true
}
```

---

### 10. Listar Bebidas

**Endpoint:** `GET /api/bebidas.php`

**Descrição:** Lista todas as bebidas disponíveis.

**Headers:**
```
Token: <jwt_token>
```

**Response Success (200):**
```json
[
  {
    "id": 1,
    "name": "Heineken",
    "description": "Cerveja Premium",
    "ibu": "23",
    "alcool": 5.0,
    "brand": "Heineken",
    "type": "Lager",
    "value": 5.00,
    "promotional_value": 4.50,
    "image": "uploads/bebidas/heineken.jpg",
    "image_url": "https://seudominio.com.br/uploads/bebidas/heineken.jpg",
    "estabelecimento_name": "Choperia Centro"
  }
]
```

---

### 11. Listar TAPs

**Endpoint:** `GET /api/taps.php`

**Descrição:** Lista todas as TAPs ativas.

**Headers:**
```
Token: <jwt_token>
```

**Response Success (200):**
```json
[
  {
    "id": 1,
    "bebida_id": 1,
    "estabelecimento_id": 1,
    "android_id": "ABC123",
    "volume": 50.00,
    "volume_consumido": 4.50,
    "volume_atual": 45.50,
    "volume_critico": 5.00,
    "vencimento": "2025-12-31",
    "status": 1,
    "pairing_code": "12345",
    "reader_id": "reader-123",
    "bebida_name": "Heineken",
    "estabelecimento_name": "Choperia Centro"
  }
]
```

---

### 12. Webhook SumUp

**Endpoint:** `POST /api/webhook.php`

**Descrição:** Recebe notificações de status de pagamento da SumUp.

**Request Body (PIX):**
```json
{
  "id": "checkout-id-123",
  "status": "SUCCESSFUL"
}
```

**Request Body (Cartão):**
```json
{
  "payload": {
    "client_transaction_id": "checkout-id-123",
    "status": "SUCCESSFUL"
  }
}
```

**Response:**
```json
{
  "success": true
}
```

**Status possíveis:**
- `PENDING`: Aguardando pagamento
- `SUCCESSFUL`: Pagamento aprovado
- `FAILED`: Pagamento falhou
- `CANCELLED`: Pagamento cancelado

---

## Fluxo de Uso

### Fluxo Completo de Pedido

1. **App Android solicita informações da TAP:**
   ```
   POST /api/verify_tap.php
   { "android_id": "ABC123" }
   ```

2. **Cliente escolhe método de pagamento e cria pedido:**
   ```
   POST /api/create_order.php
   { "valor": 5.00, "android_id": "ABC123", "payment_method": "pix", ... }
   ```

3. **Se PIX, exibe QR Code. Se cartão, aguarda leitora.**

4. **App verifica status do pagamento periodicamente:**
   ```
   POST /api/verify_checkout.php
   { "checkout_id": "abc-123", "android_id": "ABC123" }
   ```

5. **Quando pagamento aprovado, marca liberação iniciada:**
   ```
   POST /api/liberacao.php?action=iniciada
   { "checkout_id": "abc-123" }
   ```

6. **App libera o líquido e atualiza volume:**
   ```
   POST /api/liquido_liberado.php
   { "android_id": "ABC123", "qtd_ml": 100 }
   ```

7. **Marca liberação finalizada:**
   ```
   POST /api/liberacao.php?action=finalizada
   { "checkout_id": "abc-123", "qtd_ml": 100 }
   ```

### Fluxo de Cancelamento

1. **App cancela pedido:**
   ```
   POST /api/cancel_order.php
   { "checkout_id": "abc-123" }
   ```

---

## Códigos de Status HTTP

- `200`: Sucesso
- `201`: Criado com sucesso
- `400`: Requisição inválida
- `401`: Não autorizado (token inválido)
- `404`: Recurso não encontrado
- `500`: Erro interno do servidor

---

## Observações Importantes

1. **Quantidade em Mililitros:** O campo `quantidade` nos pedidos é sempre em mililitros (ml).

2. **Volume Consumido:** O endpoint `liquido_liberado.php` recebe `qtd_ml` em **centilitros** e converte para litros dividindo por 100.

3. **Webhook SumUp:** Configure a URL do webhook no painel SumUp para receber atualizações automáticas de status.

4. **Reader ID:** Para pagamentos com cartão, a TAP deve ter um `reader_id` configurado (obtido através do pareamento).

5. **Timeout PIX:** Checkouts PIX têm validade de 2 minutos. Após esse período, expiram automaticamente.

---

## Exemplos de Integração

### Exemplo em JavaScript (App Android WebView)

```javascript
// Login
async function login(email, password) {
  const response = await fetch('https://seudominio.com.br/api/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  
  const data = await response.json();
  localStorage.setItem('token', data.token);
  return data;
}

// Verificar TAP
async function verifyTap(androidId) {
  const token = localStorage.getItem('token');
  const response = await fetch('https://seudominio.com.br/api/verify_tap.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Token': token
    },
    body: JSON.stringify({ android_id: androidId })
  });
  
  return await response.json();
}

// Criar pedido PIX
async function createOrderPix(androidId, valor, quantidade, cpf) {
  const token = localStorage.getItem('token');
  const response = await fetch('https://seudominio.com.br/api/create_order.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Token': token
    },
    body: JSON.stringify({
      android_id: androidId,
      valor: valor,
      descricao: `Chopp ${quantidade}ml`,
      payment_method: 'pix',
      quantidade: quantidade,
      cpf: cpf
    })
  });
  
  return await response.json();
}

// Verificar pagamento
async function verifyCheckout(androidId, checkoutId) {
  const token = localStorage.getItem('token');
  const response = await fetch('https://seudominio.com.br/api/verify_checkout.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Token': token
    },
    body: JSON.stringify({
      android_id: androidId,
      checkout_id: checkoutId
    })
  });
  
  const data = await response.json();
  return data.status === 'success';
}

// Atualizar volume consumido
async function updateVolume(androidId, qtdMl) {
  const token = localStorage.getItem('token');
  await fetch('https://seudominio.com.br/api/liquido_liberado.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Token': token
    },
    body: JSON.stringify({
      android_id: androidId,
      qtd_ml: qtdMl
    })
  });
}
```

---

## Integração SumUp

### Configuração

1. Obtenha o token da API SumUp no painel de desenvolvedor
2. Configure no sistema em **Admin > Pagamentos**
3. Para usar cartão, configure o `pairing_code` em cada TAP

### Merchant Code

O sistema utiliza o merchant code: **MCTSYDUE**

### Webhook URL

Configure no painel SumUp:
```
https://seudominio.com.br/api/webhook.php
```

### Métodos de Pagamento

- **PIX**: Gera QR Code dinâmico com validade de 2 minutos
- **Crédito**: Processa via leitora de cartão SumUp
- **Débito**: Processa via leitora de cartão SumUp

---

## Logs

O sistema gera logs em:
- `/logs/webhook.log`: Logs de webhooks recebidos da SumUp

---

## Segurança

1. **JWT Secret:** Altere o `JWT_SECRET` no arquivo `includes/config.php`
2. **HTTPS:** Use sempre HTTPS em produção
3. **Token Expiration:** Tokens JWT não expiram. Implemente lógica de expiração se necessário
4. **Rate Limiting:** Implemente rate limiting no servidor para evitar abuso

---

## Suporte

Para dúvidas ou problemas:
- Email: choppon24h@gmail.com

## Diagnostico SumUp Cloud API (Atualizacao 2026-02-22)

1. Configure em **Admin > Pagamentos**:
   - `token_sumup` (`sup_sk_...`)
   - `affiliate_key` (`sup_afk_...`)
   - `affiliate_app_id` (App Identifier cadastrado na Affiliate Key)
2. Na SumUp Solo, sem `Connections > API > Connect` a leitora fica pareada mas OFFLINE.
3. Status esperado no dispositivo para transacionar: `Connected - Ready to transact`.
4. Erros comuns:
   - `422 / READER_OFFLINE`: leitora sem conexao ativa na API.
   - `401/403`: token invalido/sem escopo.
   - `404` em Readers API com token valido em checkouts: `merchant_code` divergente.
5. Logs de integracao:
   - `/logs/paymentslogs.log` (check_api, checkout e webhook)
   - `/logs/webhook.log`
