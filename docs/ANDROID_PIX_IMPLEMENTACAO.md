# Análise Android × API PHP — Fluxo PIX ChoppOnTap

**Versão:** 1.0  
**Data:** Março de 2026  
**Repositório PHP:** [choppon24h-png/chopponERP](https://github.com/choppon24h-png/chopponERP)  
**Repositório Android:** [choppon24h-png/ChoppAndroid](https://github.com/choppon24h-png/ChoppAndroid)

---

## Resumo Executivo

Após análise completa do código Android (`ChoppAndroid`) cruzada com a API PHP corrigida (`chopponERP` v3.0.0), o resultado é:

> **O app Android NÃO precisa de alterações estruturais para o fluxo PIX funcionar.** A arquitetura atual já está correta e compatível com a API corrigida. Existem, porém, **3 melhorias de qualidade** que devem ser implementadas para tornar a experiência mais robusta, e **1 ajuste crítico** no modelo `Qr.java` para suportar o novo campo `pix_code` retornado pela API.

A tabela abaixo resume o diagnóstico completo:

| Componente Android | Status | Ação Necessária |
|---|---|---|
| `Qr.java` — modelo de resposta | Incompleto | **Adicionar campo `pix_code`** |
| `FormaPagamento.java` — `updateQrCode()` | Funcional | Melhoria: exibir "copia e cola" |
| `FormaPagamento.java` — `verifyPayment()` | Funcional | Melhoria: aceitar status `"PAID"` |
| `FormaPagamento.java` — tela PIX vazia | Funcional | Melhoria: exibir placeholder |
| `CheckoutResponse.java` | Correto | Nenhuma ação necessária |
| `ApiHelper.java` | Correto | Nenhuma ação necessária |
| `activity_forma_pagamento.xml` | Correto | Adicionar `TextView` para `pix_code` |

---

## 1. Contexto: O Que a API PHP Agora Retorna

Com a correção v3.0.0 do backend, o endpoint `create_order.php` passou a retornar dois campos novos para o fluxo PIX:

```json
{
  "success": true,
  "checkout_id": "4e425463-3e1b-431d-83fa-1e51c2925e99",
  "qr_code": "iVBORw0KGgoAAAANSUhEUgAA...",
  "pix_code": "00020126580014br.gov.bcb.pix0136a4fac492-d03b-45a8-bd43-c3f23d4bac6852040000530398654052..."
}
```

O campo `pix_code` contém o **código EMV** (também chamado "copia e cola" do PIX), que permite ao cliente pagar sem precisar escanear o QR Code — útil quando a câmera do cliente não consegue ler o QR Code na tela do tablet.

O campo `qr_code` continua funcionando exatamente como antes (Base64 da imagem PNG/JPEG do QR Code).

---

## 2. Ajuste Necessário — `Qr.java` (OBRIGATÓRIO)

### Problema

O modelo `Qr.java` não possui o campo `pix_code`. Quando o Gson faz o `fromJson()` da resposta da API, o campo `pix_code` é simplesmente ignorado — não causa erro, mas o dado se perde.

### Arquivo: `app/src/main/java/com/example/choppontap/Qr.java`

**Antes:**
```java
public class Qr {
    public String qr_code;
    public String checkout_id;
    public String card_type;
    public String reader_name;
    public String reader_serial;
    public String reader_id;
}
```

**Depois (adicionar o campo `pix_code`):**
```java
public class Qr {
    /** Base64 da imagem do QR Code (apenas PIX) */
    public String qr_code;

    /** Código EMV "copia e cola" do PIX (apenas PIX) */
    public String pix_code;

    /** ID do checkout SumUp */
    public String checkout_id;

    /** Tipo de cartão: "debit" ou "credit" (apenas cartão) */
    public String card_type;

    /** Nome da leitora SumUp vinculada (apenas cartão) */
    public String reader_name;

    /** Serial/identificador físico da leitora (apenas cartão) */
    public String reader_serial;

    /** ID lógico da leitora na SumUp (apenas cartão) */
    public String reader_id;
}
```

---

## 3. Melhoria Recomendada — Exibir Código "Copia e Cola" no Layout PIX

### Contexto

Atualmente, a tela PIX (`layoutQrPix`) exibe apenas o QR Code. Com o campo `pix_code` disponível, é possível exibir o código EMV abaixo do QR Code com um botão "Copiar", melhorando a experiência em casos onde o cliente não consegue escanear.

### Arquivo: `app/src/main/res/layout/activity_forma_pagamento.xml`

Adicionar dentro de `layoutQrPix`, após o `CardView` do QR Code e antes do `btnConfirmarPagamento`:

```xml
<!-- Código PIX "Copia e Cola" -->
<TextView
    android:id="@+id/txtPixCodeLabel"
    android:layout_width="wrap_content"
    android:layout_height="wrap_content"
    android:layout_marginTop="12dp"
    android:text="Ou copie o código PIX:"
    android:textColor="@color/text_primary"
    android:textSize="14sp"
    android:visibility="gone" />

<TextView
    android:id="@+id/txtPixCode"
    android:layout_width="300dp"
    android:layout_height="wrap_content"
    android:layout_marginTop="4dp"
    android:background="@drawable/rounded_bg"
    android:ellipsize="end"
    android:maxLines="2"
    android:padding="8dp"
    android:textColor="@color/text_primary"
    android:textSize="11sp"
    android:visibility="gone" />

<com.google.android.material.button.MaterialButton
    android:id="@+id/btnCopiarPix"
    style="@style/Widget.Material3.Button.OutlinedButton"
    android:layout_width="300dp"
    android:layout_height="48dp"
    android:layout_marginTop="4dp"
    android:text="COPIAR CÓDIGO PIX"
    android:textColor="@color/choppon_orange"
    android:visibility="gone"
    app:cornerRadius="8dp"
    app:strokeColor="@color/choppon_orange" />
```

### Arquivo: `FormaPagamento.java`

Adicionar os novos elementos no `setupUI()`:

```java
private TextView txtPixCode;
private TextView txtPixCodeLabel;
private Button btnCopiarPix;
```

```java
// No setupUI():
txtPixCode      = findViewById(R.id.txtPixCode);
txtPixCodeLabel = findViewById(R.id.txtPixCodeLabel);
btnCopiarPix    = findViewById(R.id.btnCopiarPix);

btnCopiarPix.setOnClickListener(v -> {
    if (txtPixCode.getText() != null && !txtPixCode.getText().toString().isEmpty()) {
        android.content.ClipboardManager clipboard =
            (android.content.ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        android.content.ClipData clip =
            android.content.ClipData.newPlainText("PIX", txtPixCode.getText().toString());
        clipboard.setPrimaryClip(clip);
        Toast.makeText(this, "Código PIX copiado!", Toast.LENGTH_SHORT).show();
    }
});
```

Atualizar o método `updateQrCode()` para também exibir o código EMV:

```java
public void updateQrCode(Qr qr) {
    // Exibir imagem do QR Code
    if (qr.qr_code != null && !qr.qr_code.isEmpty()) {
        try {
            byte[] b   = Base64.decode(qr.qr_code, Base64.DEFAULT);
            Bitmap bmp = BitmapFactory.decodeByteArray(b, 0, b.length);
            runOnUiThread(() -> {
                imageView.setImageBitmap(bmp);
                Log.d(TAG, "QR Code exibido com sucesso (" + b.length + " bytes)");
            });
        } catch (Exception e) {
            Log.e(TAG, "Erro ao decodificar QR Code: " + e.getMessage());
        }
    } else {
        Log.w(TAG, "qr_code vazio ou nulo na resposta da API");
        // Exibir placeholder para não deixar tela em branco
        runOnUiThread(() -> imageView.setImageResource(R.drawable.ic_qr_placeholder));
    }

    // Exibir código "copia e cola" se disponível
    if (qr.pix_code != null && !qr.pix_code.isEmpty()) {
        runOnUiThread(() -> {
            txtPixCode.setText(qr.pix_code);
            txtPixCode.setVisibility(View.VISIBLE);
            txtPixCodeLabel.setVisibility(View.VISIBLE);
            btnCopiarPix.setVisibility(View.VISIBLE);
            Log.d(TAG, "Código PIX EMV exibido (" + qr.pix_code.length() + " chars)");
        });
    }
}
```

---

## 4. Melhoria Recomendada — `verifyPayment()`: Aceitar Status `"PAID"`

### Contexto

O método `verifyPayment()` em `FormaPagamento.java` verifica o sucesso do pagamento assim:

```java
boolean isSuccess = cr.status != null
    && (cr.status.equalsIgnoreCase("success")
        || (cr.checkout_status != null
            && cr.checkout_status.equalsIgnoreCase("SUCCESSFUL")));
```

A API PHP corrigida retorna `{ "status": "success" }` quando o pagamento é aprovado — o que já é tratado corretamente. Porém, o campo `checkout_status` aceito é apenas `"SUCCESSFUL"`, enquanto a SumUp pode retornar `"PAID"` para transações PIX.

### Correção em `FormaPagamento.java`

```java
// Linha ~407 — ampliar os status aceitos como aprovados
boolean isSuccess = cr.status != null
    && (cr.status.equalsIgnoreCase("success")
        || (cr.checkout_status != null
            && (cr.checkout_status.equalsIgnoreCase("SUCCESSFUL")
                || cr.checkout_status.equalsIgnoreCase("PAID")
                || cr.checkout_status.equalsIgnoreCase("APPROVED")
                || cr.checkout_status.equalsIgnoreCase("COMPLETED"))));
```

---

## 5. Melhoria Recomendada — Placeholder Quando QR Code Está Vazio

### Contexto

Se por qualquer motivo o `qr_code` vier vazio (falha temporária na geração), o `ImageView` do QR Code fica em branco sem nenhuma indicação ao usuário. Isso causa confusão — o cliente vê uma tela vazia e não sabe o que fazer.

### Solução

Criar um drawable `ic_qr_placeholder.xml` em `res/drawable/`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="300dp"
    android:height="300dp"
    android:viewportWidth="300"
    android:viewportHeight="300">
    <path
        android:fillColor="#CCCCCC"
        android:pathData="M0,0h300v300H0z"/>
    <path
        android:fillColor="#888888"
        android:pathData="M120,130 L120,170 L180,170 L180,130 Z"/>
    <path
        android:fillColor="#FFFFFF"
        android:pathData="M130,140 L130,160 L170,160 L170,140 Z"/>
</vector>
```

E adicionar um `TextView` de instrução abaixo do `ImageView` no layout:

```xml
<TextView
    android:id="@+id/txtQrError"
    android:layout_width="300dp"
    android:layout_height="wrap_content"
    android:gravity="center"
    android:text="QR Code indisponível.\nUse o código copia e cola abaixo."
    android:textColor="@color/error"
    android:textSize="14sp"
    android:visibility="gone" />
```

No `updateQrCode()`, exibir a mensagem quando `qr_code` estiver vazio:

```java
if (qr.qr_code == null || qr.qr_code.isEmpty()) {
    runOnUiThread(() -> {
        imageView.setImageResource(R.drawable.ic_qr_placeholder);
        TextView txtQrError = findViewById(R.id.txtQrError);
        if (txtQrError != null) txtQrError.setVisibility(View.VISIBLE);
    });
}
```

---

## 6. O Que NÃO Precisa Ser Alterado

A análise confirmou que os seguintes componentes estão **corretos e compatíveis** com a API corrigida:

**`ApiHelper.java`** — A configuração HTTP/2 via ALPN, os timeouts e o pool de conexões estão corretos. O diagnóstico forense do log de 2026-03-03 foi aplicado corretamente: não forçar HTTP/1.1 e deixar o OkHttp negociar HTTP/2 nativo.

**`CheckoutResponse.java`** — O modelo de resposta do `verify_checkout.php` está correto. O campo `status: "success"` é retornado pela API quando aprovado, e o campo `checkout_status` com os valores `PAID/SUCCESSFUL` é tratado corretamente.

**`sendRequest()` em `FormaPagamento.java`** — O corpo da requisição para `create_order.php` está correto: `android_id`, `cpf`, `valor`, `quantidade`, `descricao`, `payment_method`. Todos os campos obrigatórios estão presentes.

**`startVerifing()` e `startCountDown()`** — O polling de 7 segundos com timeout de 180s (PIX) e 120s (cartão) está alinhado com o `valid_until` de 3 minutos configurado no backend.

**`SendCardCancel()`** — O cancelamento de transação de cartão via `cancel_order.php` está implementado corretamente.

**`PagamentoConcluido.java`** — A liberação via Bluetooth e o registro de início/fim da liberação estão corretos.

---

## 7. Fluxo Completo Após as Correções

O diagrama abaixo mostra o fluxo completo após as correções no PHP e as melhorias no Android:

```
ANDROID (FormaPagamento)              PHP (create_order.php)         SUMUP API
─────────────────────────             ──────────────────────         ─────────
[1] Usuário toca "PIX"
[2] sendRequest("pix") ──────────────→ Valida JWT + campos
                                       Cria pedido no banco
                                       ┌─────────────────────────────→ POST /v0.1/checkouts
                                       │                               ← { id, status:PENDING }
                                       └─────────────────────────────→ PUT  /v0.1/checkouts/{id}
                                                                          { payment_type:"pix" }
                                                                       ← { pix.artefacts: [
                                                                              barcode (JPEG URL),
                                                                              code (EMV string)
                                                                            ] }
                                       Baixa imagem JPEG da SumUp
                                       Converte para Base64
                        ←────────────── { checkout_id, qr_code, pix_code }
[3] updateQrCode(qr)
    → Exibe QR Code (Base64)
    → Exibe pix_code (copia e cola)  ← NOVO
    → Exibe botão "Copiar"           ← NOVO
[4] startVerifing(checkout_id, 180)
[5] verifyPayment() a cada 7s ───────→ verify_checkout.php
                                       Consulta banco local
                                       Se PENDING: GET /v0.1/checkouts/{id} ← NOVO
                        ←────────────── { status: "success" }
[6] navigateToSuccess()
[7] PagamentoConcluido
    → Libera chopp via Bluetooth
```

---

## 8. Prioridade de Implementação

| Prioridade | Item | Arquivo | Impacto |
|---|---|---|---|
| **ALTA** | Adicionar campo `pix_code` em `Qr.java` | `Qr.java` | Habilita o código copia e cola |
| **ALTA** | Exibir `pix_code` na tela + botão copiar | `FormaPagamento.java` + XML | UX crítica quando QR Code falha |
| **MÉDIA** | Aceitar status `PAID` em `verifyPayment()` | `FormaPagamento.java` | Evita falso-negativo em PIX |
| **BAIXA** | Placeholder quando `qr_code` está vazio | `FormaPagamento.java` + XML | UX de fallback |

---

## 9. Teste de Validação

Após implementar as alterações, validar com o seguinte roteiro:

**Teste 1 — QR Code normal (caminho feliz):**
Selecionar PIX → verificar que o QR Code aparece → verificar que o código copia e cola aparece abaixo → escanear o QR Code com outro celular → verificar que o app navega para `PagamentoConcluido`.

**Teste 2 — Código copia e cola:**
Selecionar PIX → tocar no botão "COPIAR CÓDIGO PIX" → verificar Toast "Código PIX copiado!" → colar em qualquer app e verificar que o código EMV está completo (começa com `000201`).

**Teste 3 — Polling de status:**
Selecionar PIX → pagar via outro celular → aguardar até 7 segundos → verificar que o app detecta o pagamento e navega para `PagamentoConcluido` sem precisar tocar em "CONFIRMAR PAGAMENTO".

**Teste 4 — Timeout:**
Selecionar PIX → aguardar 180 segundos sem pagar → verificar que o app volta para a tela de escolha de pagamento automaticamente.

**Logs esperados no `adb logcat` após as correções:**
```
D/PAGAMENTO_DEBUG: 📦 create_order resposta HTTP 200: {"success":true,"checkout_id":"...","qr_code":"iVBOR...","pix_code":"000201..."}
D/PAGAMENTO_DEBUG: QR Code exibido com sucesso (8432 bytes)
D/PAGAMENTO_DEBUG: Código PIX EMV exibido (120 chars)
D/PAGAMENTO_DEBUG: 🔍 RESPOSTA VERIFICAÇÃO HTTP 200: {"status":"success"}
D/PAGAMENTO_DEBUG: 💰 PAGAMENTO APROVADO! Redirecionando para PagamentoConcluido...
```
