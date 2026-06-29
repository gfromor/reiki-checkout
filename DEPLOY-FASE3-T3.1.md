# 🚀 Migração T3.1 — Tirar o backend do WPCode → Plugin versionado (Hostinger)

> Objetivo: o código do backend passa a ser um arquivo versionado (`mu-plugin`), e as **chaves** ficam isoladas no `wp-config.php`. Deploy passa a ser por arquivo/git; as chaves não se movem mais junto com o código.
>
> **Pré-requisito:** acesso a arquivos (Hostinger hPanel → Gerenciador de Arquivos, ou SFTP). Você tem. ✅
>
> ⚠️ Faça num horário de baixo movimento. O corte real tem uma janela de ~10 segundos.

---

## Visão geral do que vai acontecer

- Hoje: código **+ chaves** vivem dentro do snippet do **WPCode** (no banco).
- Depois: código no arquivo `wp-content/mu-plugins/reiki-backend.php` (este repo) e **chaves no `wp-config.php`**.
- O arquivo `backend-wpcode.php` deste repo **já é o plugin** (tem cabeçalho de plugin e lê as chaves do wp-config via `if(!defined())`).

---

## Passo 0 — Coletar suas chaves atuais (do WPCode)

Abra o snippet atual no **WPCode** e copie os valores reais que estão preenchidos lá:

- `REIKI_ASAAS_API_KEY`
- `REIKI_ASAAS_WEBHOOK_TOKEN`
- `REIKI_STRIPE_SECRET_KEY`
- `REIKI_STRIPE_WEBHOOK_SECRET`
- `REIKI_TURNSTILE_SECRET_KEY`
- `REIKI_ONESIGNAL_REST_KEY`

(O `REIKI_ADMIN_SECRET` e o `REIKI_ADMIN_PIN_HASH` você já colocou no wp-config na Fase 1.)

> Se no WPCode esses estiverem vazios e as chaves reais estiverem em outro lugar, use as reais. Sem essas chaves o checkout não cobra.

---

## Passo 1 — Colocar as chaves no `wp-config.php`

hPanel → **Gerenciador de Arquivos** → pasta do WordPress (geralmente `public_html/`) → editar **`wp-config.php`**.

Cole o bloco abaixo **ACIMA** da linha `/* That's all, stop editing! Happy publishing. */`, preenchendo com os valores do Passo 0. **Use aspas simples** (por causa dos `$` em alguns valores):

```php
/* === Reiki Time — Segredos (NUNCA versionar) === */
define('REIKI_ASAAS_API_KEY',         'COLE_AQUI');
define('REIKI_ASAAS_WEBHOOK_TOKEN',   'COLE_AQUI');
define('REIKI_STRIPE_SECRET_KEY',     'COLE_AQUI');   // sk_live_...
define('REIKI_STRIPE_WEBHOOK_SECRET', 'COLE_AQUI');   // whsec_...
define('REIKI_TURNSTILE_SECRET_KEY',  'COLE_AQUI');
define('REIKI_ONESIGNAL_REST_KEY',    'COLE_AQUI');
/* REIKI_ADMIN_SECRET e REIKI_ADMIN_PIN_HASH já devem estar definidos (Fase 1) */
```

Salve. **Neste momento nada muda em produção** (o WPCode ainda tem as chaves embutidas; vão aparecer alguns *warnings* "constant already defined" no log até o Passo 3 — é esperado e inofensivo).

> Confirme que `ASAAS_IS_SANDBOX` deve continuar `false` (produção). O plugin já assume `false` por padrão.

---

## Passo 2 — Subir o plugin (inativo) para `mu-plugins`

1. No Gerenciador de Arquivos, vá em `wp-content/`. Se não existir a pasta **`mu-plugins`**, crie.
2. Faça **upload** do arquivo `backend-wpcode.php` (deste repo) para dentro de `wp-content/mu-plugins/`.
3. **Renomeie** para **`reiki-backend.php.txt`** (com `.txt` no fim → assim o WordPress **não** carrega ainda).

Nada muda em produção ainda (arquivo `.txt` é ignorado).

---

## Passo 3 — O corte (janela de ~10s)

Faça os dois na sequência, rápido:

1. **WPCode** → desative o snippet do checkout (toggle "Inactive") e salve.
   - A partir daqui o backend está **fora do ar** por alguns segundos (checkout retorna 404).
2. **Gerenciador de Arquivos** → renomeie `reiki-backend.php.txt` → **`reiki-backend.php`** (tira o `.txt`).
   - O WordPress carrega o mu-plugin imediatamente. Backend **de volta ao ar**, agora lendo as chaves do wp-config.

> Por que não pode ter os dois ativos juntos: WPCode e plugin declaram as mesmas funções → erro fatal "Cannot redeclare function". Por isso desativa o WPCode **antes** de ativar o arquivo.

---

## Passo 4 — Validação (me chama que eu rodo, ou você confere)

- `GET https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/catalog` → deve responder 200 com os produtos.
- Login no painel (token) deve funcionar.
- Um checkout de teste (PIX) deve gerar QR.
- Webhook: um pagamento de teste deve liberar acesso.

Eu valido `/catalog` + `/admin-login` na hora pela API.

---

## Rollback (se algo der errado)

1. Renomeie `reiki-backend.php` → `reiki-backend.php.txt` (desativa o plugin).
2. **WPCode** → reative o snippet antigo.
   Pronto, volta ao estado anterior em segundos. (As chaves no wp-config podem ficar; não atrapalham o WPCode além dos warnings.)

---

## Depois que estabilizar

- Deixe o snippet do WPCode **desativado** (não delete já; é seu rollback por alguns dias).
- A partir de agora, mudança no backend = editar `reiki-backend.php` no git → subir o arquivo pro `mu-plugins`. Sem mais copiar-colar no WPCode.
- Garanta permissão restrita no `wp-config.php` (chmod 640) e que ele **nunca** entre no git.
- Opcional (próximo nível): script/CI que faz o upload via SFTP no `git push`.
