# Diretório de Distribuição do APK — ChoppON

Este diretório é o ponto de distribuição do aplicativo Android ChoppON.
Ele deve ser hospedado em `https://ochoppoficial.com.br/app/`.

## Estrutura

| Arquivo | Descrição |
|---|---|
| `version.json` | Manifesto de versão lido pelo app para verificar atualizações |
| `app-release.apk` | APK mais recente do aplicativo (fazer upload manualmente) |
| `.htaccess` | Configuração Apache: MIME type correto + sem cache no JSON |

## Como publicar uma nova versão

### 1. Compilar o APK no Android Studio

```
Build → Generate Signed Bundle / APK → APK → Release
```

O arquivo gerado estará em:
`app/release/app-release.apk`

### 2. Fazer upload do APK para o servidor

Via FTP ou cPanel, enviar o arquivo para:
```
/home2/inlaud99/ochoppoficial.com.br/app/app-release.apk
```

### 3. Atualizar o `version.json`

Editar o arquivo `version.json` com os novos dados:

```json
{
  "versionCode": 2,
  "versionName": "1.1.0",
  "apkUrl": "https://ochoppoficial.com.br/app/app-release.apk",
  "force": false,
  "changelog": "Descrição do que mudou nesta versão."
}
```

> **Importante:** `versionCode` deve ser **maior** que o `versionCode` definido
> em `app/build.gradle.kts` do projeto Android. O app compara os dois valores
> para decidir se há atualização disponível.

### 4. Fazer upload do `version.json` atualizado

```
/home2/inlaud99/ochoppoficial.com.br/app/version.json
```

### Campos do `version.json`

| Campo | Tipo | Descrição |
|---|---|---|
| `versionCode` | `int` | Código numérico da versão (deve ser maior que o atual) |
| `versionName` | `string` | Nome legível da versão (ex: `"1.2.0"`) |
| `apkUrl` | `string` | URL completa do APK para download |
| `force` | `boolean` | `true` = atualização obrigatória (remove botão "Agora não") |
| `changelog` | `string` | Descrição das mudanças exibida no diálogo |

### Como acionar a verificação no app

O botão **"Verificar Atualização"** (azul) na tela **ServiceTools** aciona
o método `ServiceTools.checkAppUpdate(context)`.

Para acionar de qualquer outra Activity futuramente:

```java
ServiceTools.checkAppUpdate(this);
```
