<?php
/**
 * Plugin Name: Reiki Time — Checkout & Webhooks
 * Description: Checkout universal (Asaas + Stripe), webhooks, memberships, dashboard e link builder.
 * Version: 1.0.0
 * Author: Reiki Time Academy
 *
 * INSTALAÇÃO: copiar este arquivo para wp-content/mu-plugins/reiki-backend.php
 * SEGREDOS (chaves de API) ficam SEMPRE no wp-config.php, NUNCA neste arquivo.
 * Passo a passo de migração: ver DEPLOY-FASE3-T3.1.md
 * IMPORTANTE: nunca deixe este plugin E o snippet WPCode ativos ao mesmo tempo
 * (declaram as mesmas funções -> "Cannot redeclare function"). Desative o WPCode antes.
 */

// 1. Configurações Iniciais do ASAAS (valores reais ficam no wp-config.php)
if (!defined('REIKI_ASAAS_API_KEY'))       define('REIKI_ASAAS_API_KEY', '');
if (!defined('REIKI_ASAAS_WEBHOOK_TOKEN')) define('REIKI_ASAAS_WEBHOOK_TOKEN', '');
if (!defined('ASAAS_IS_SANDBOX'))          define('ASAAS_IS_SANDBOX', false);

// =========================================================================
// AUTENTICAÇÃO ADMIN (PWA Dashboard / Link Builder)  [Fase 1 - Hardening]
// =========================================================================
// IMPORTANTE: defina os dois itens abaixo no wp-config.php, NUNCA neste snippet:
//   define('REIKI_ADMIN_SECRET', '<32+ caracteres aleatorios>');
//   define('REIKI_ADMIN_PIN_HASH', '<resultado de password_hash("SEU_PIN", PASSWORD_DEFAULT)>');
// Enquanto não definir, há um fallback para não derrubar a produção.
if (!defined('REIKI_ADMIN_SECRET'))     define('REIKI_ADMIN_SECRET', defined('AUTH_KEY') ? AUTH_KEY : 'TROCAR_NO_WP_CONFIG');
if (!defined('REIKI_ADMIN_PIN_HASH'))   define('REIKI_ADMIN_PIN_HASH', ''); // vazio => usa o PIN legado p/ login
if (!defined('REIKI_LEGACY_PIN'))       define('REIKI_LEGACY_PIN', '20192021NZ');
// TRANSIÇÃO: deixe true até publicar o frontend novo (login por token). Depois mude para false.
if (!defined('REIKI_ALLOW_LEGACY_PIN')) define('REIKI_ALLOW_LEGACY_PIN', true);
// Token da API de venda externa (idfeminino). Mova p/ wp-config e rotacione; fallback mantém a integração viva.
if (!defined('REIKI_EXTERNAL_API_KEY')) define('REIKI_EXTERNAL_API_KEY', 'REIKI_EXT_2026_NZ');

// IP real do cliente (CF-Connecting-IP atrás do Cloudflare; senão REMOTE_ADDR).
function reiki_client_ip() {
    return isset($_SERVER['HTTP_CF_CONNECTING_IP'])
        ? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'])
        : sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0');
}

// Rate limit por IP (usa CF-Connecting-IP atrás do Cloudflare). true = liberado.
function reiki_admin_rate($scope, $max = 8, $janela = 900) {
    $ip = reiki_client_ip();
    $k = 'reiki_rl_' . $scope . '_' . md5($ip);
    $n = (int) get_transient($k);
    if ($n >= $max) return false;
    set_transient($k, $n + 1, $janela);
    return true;
}

// CORS restrito para os endpoints administrativos (substitui o antigo "*")
function reiki_admin_cors() {
    $allowed = array('https://app.reikitimeacademy.com.br', 'http://localhost:5173');
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed)) header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, x-admin-token, x-dashboard-pin");
}

// Gera um token assinado (HMAC) com expiração. Padrão: 30 dias.
function reiki_make_admin_token($ttl = 2592000) {
    $exp = time() + $ttl;
    return $exp . '.' . hash_hmac('sha256', (string) $exp, REIKI_ADMIN_SECRET);
}

function reiki_valid_admin_token($token) {
    if (!is_string($token) || strpos($token, '.') === false) return false;
    list($exp, $sig) = explode('.', $token, 2);
    if (!ctype_digit($exp) || intval($exp) < time()) return false;
    return hash_equals(hash_hmac('sha256', $exp, REIKI_ADMIN_SECRET), $sig);
}

// Autoriza um request admin: token novo (header x-admin-token) OU PIN legado (transição).
function reiki_is_admin( WP_REST_Request $request ) {
    $token = $request->get_header('x-admin-token');
    if ($token && reiki_valid_admin_token($token)) return true;

    if (REIKI_ALLOW_LEGACY_PIN) {
        $pin = $request->get_header('x-dashboard-pin');
        if (!$pin) $pin = $request->get_param('pin');
        if (!$pin) {
            $p = $request->get_json_params();
            if (is_array($p) && isset($p['pin'])) $pin = $p['pin'];
        }
        if ($pin && hash_equals(REIKI_LEGACY_PIN, (string) $pin)) return true;
    }
    return false;
}

// Endpoint de login: troca o PIN por um token assinado.
function reiki_admin_login_api( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );

    if (!reiki_admin_rate('admin_login', 8, 900)) {
        return new WP_Error('rate_limit', 'Muitas tentativas. Aguarde 15 minutos.', array('status' => 429));
    }

    $params = $request->get_json_params();
    $pin = isset($params['pin']) ? (string) $params['pin'] : '';

    $ok = false;
    if (REIKI_ADMIN_PIN_HASH !== '') {
        $ok = password_verify($pin, REIKI_ADMIN_PIN_HASH);
    } elseif (REIKI_ALLOW_LEGACY_PIN) {
        $ok = hash_equals(REIKI_LEGACY_PIN, $pin); // fallback até configurar o hash no wp-config
    }

    if (!$ok) return new WP_Error('nao_autorizado', 'PIN incorreto', array('status' => 401));

    return rest_ensure_response(array('token' => reiki_make_admin_token(), 'exp' => time() + 2592000));
}

// =========================================================================
// HELPER FUNÇÕES ASAAS (PAYMENTS, AUTH & CAPTURE)
// =========================================================================
function reiki_asaas_request($method, $endpoint, $body = null) {
    $base_url = ASAAS_IS_SANDBOX ? 'https://sandbox.asaas.com/api/v3' : 'https://api.asaas.com/v3';
    $headers = array('Content-Type' => 'application/json', 'access_token' => REIKI_ASAAS_API_KEY);
    $args = array('headers' => $headers, 'method' => $method, 'timeout' => 30);
    if ($body) $args['body'] = json_encode($body);
    return wp_remote_request($base_url . $endpoint, $args);
}

function reiki_asaas_cancelar($payment_id) {
    return reiki_asaas_request('DELETE', '/payments/' . $payment_id);
}

function reiki_asaas_capturar($payment_id) {
    return reiki_asaas_request('POST', '/payments/' . $payment_id . '/captureAuthorized');
}

function reiki_asaas_montar_cartao($card_data, $valor_total, $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero) {
    $parcelas = intval($card_data['parcelas']);
    $interest_rates = get_reiki_interest_rates(); // fonte única (o front consome a mesma via /catalog)
    $rate = isset($interest_rates[$parcelas]) ? $interest_rates[$parcelas] : 0;
    $valor_com_juros = $valor_total * (1 + $rate / 100);
    
    $cc_expiry = sanitize_text_field( $card_data['cc_expiry'] );
    $exp_parts = explode('/', $cc_expiry);
    
    return array(
        'installmentCount' => $parcelas,
        'installmentValue' => round($valor_com_juros / $parcelas, 2),
        'creditCard' => array(
            'holderName' => sanitize_text_field( $card_data['cc_name'] ),
            'number' => preg_replace('/[^0-9]/', '', $card_data['cc_number']),
            'expiryMonth' => trim($exp_parts[0]),
            'expiryYear' => (strlen(trim($exp_parts[1])) == 2) ? '20'.trim($exp_parts[1]) : trim($exp_parts[1]),
            'ccv' => sanitize_text_field( $card_data['cc_cvv'] )
        ),
        'creditCardHolderInfo' => array(
            'name' => $nome, 'email' => $email, 'cpfCnpj' => preg_replace('/[^0-9]/', '', $cpf),
            'postalCode' => sanitize_text_field( $cep ),
            'addressNumber' => sanitize_text_field( $numero ),
            'phone' => preg_replace('/[^0-9]/', '', $telefone),
            'remoteIp' => $ip_cliente
        )
    );
}

function reiki_asaas_cobranca($customer_id, $billing_type, $valor, $vencimento, $descricao, $external_ref, $cartao_extra = null, $authorize_only = false) {
    $body = array(
        'customer' => $customer_id,
        'billingType' => $billing_type,
        'value' => $valor,
        'dueDate' => $vencimento,
        'description' => $descricao,
        'externalReference' => $external_ref
    );
    if ($authorize_only) {
        $body['authorizeOnly'] = true;
    }
    if ($cartao_extra) {
        foreach ($cartao_extra as $k => $v) {
            $body[$k] = $v;
        }
    }
    $response = reiki_asaas_request('POST', '/payments', $body);
    if (is_wp_error($response)) {
        return new WP_Error('erro_api', 'Falha ao conectar com banco.', array('status' => 500));
    }
    $body_resp = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body_resp['errors'])) {
        return new WP_Error('recusado', $body_resp['errors'][0]['description'], array('status' => 400));
    }
    return $body_resp;
}

function reiki_asaas_cobranca_carne($customer_id, $valor_total, $parcelas, $vencimento, $descricao, $external_ref) {
    $valor_parcela = round($valor_total / $parcelas, 2);
    $body = array(
        'customer' => $customer_id,
        'billingType' => 'BOLETO',
        'value' => $valor_parcela,
        'installmentCount' => $parcelas,
        'dueDate' => $vencimento,
        'description' => $descricao,
        'externalReference' => $external_ref
    );
    $response = reiki_asaas_request('POST', '/installments', $body);
    if (is_wp_error($response)) {
        return new WP_Error('erro_api', 'Falha ao conectar com banco.', array('status' => 500));
    }
    $body_resp = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body_resp['errors'])) {
        return new WP_Error('recusado', $body_resp['errors'][0]['description'], array('status' => 400));
    }
    return $body_resp;
}

function reiki_asaas_assinatura($customer_id, $valor, $max_payments, $vencimento, $descricao, $external_ref, $cartao_extra) {
    $body = array(
        'customer' => $customer_id,
        'billingType' => 'CREDIT_CARD',
        'value' => $valor,
        'nextDueDate' => $vencimento,
        'cycle' => 'MONTHLY',
        'description' => $descricao,
        'externalReference' => $external_ref,
        'maxPayments' => $max_payments
    );
    
    if ($cartao_extra) {
        if (isset($cartao_extra['creditCard'])) $body['creditCard'] = $cartao_extra['creditCard'];
        if (isset($cartao_extra['creditCardHolderInfo'])) $body['creditCardHolderInfo'] = $cartao_extra['creditCardHolderInfo'];
    }

    $response = reiki_asaas_request('POST', '/subscriptions', $body);
    if (is_wp_error($response)) {
        return new WP_Error('erro_api', 'Falha ao conectar com banco.', array('status' => 500));
    }
    $body_resp = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body_resp['errors'])) {
        return new WP_Error('recusado', $body_resp['errors'][0]['description'], array('status' => 400));
    }
    return $body_resp;
}

// 2. Configurações Iniciais do STRIPE (valores reais ficam no wp-config.php)
if (!defined('REIKI_STRIPE_SECRET_KEY'))     define('REIKI_STRIPE_SECRET_KEY', '');
if (!defined('REIKI_STRIPE_WEBHOOK_SECRET')) define('REIKI_STRIPE_WEBHOOK_SECRET', '');
if (!defined('REIKI_TURNSTILE_SECRET_KEY'))  define('REIKI_TURNSTILE_SECRET_KEY', '');

// 3. Configurações do OneSignal (App da Fê) — REST key fica no wp-config.php
if (!defined('REIKI_ONESIGNAL_APP_ID'))  define('REIKI_ONESIGNAL_APP_ID', '43777ff2-106a-48f6-9686-c6088c26f6ce'); // público
if (!defined('REIKI_ONESIGNAL_REST_KEY')) define('REIKI_ONESIGNAL_REST_KEY', '');

// =========================================================================
// REGISTRO DE CUSTOM POST TYPE (HISTÓRICO DE VENDAS)
// =========================================================================
add_action('init', function() {
    register_post_type('reiki_venda', array(
        'labels' => array('name' => 'Vendas Checkout', 'singular_name' => 'Venda'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title', 'custom-fields'),
        'menu_icon' => 'dashicons-chart-line'
    ));

    register_post_type('reiki_custom_link', array(
        'labels' => array('name' => 'Links Customizados', 'singular_name' => 'Link'),
        'public' => false,
        'show_ui' => true,
        'supports' => array('title', 'custom-fields'),
        'menu_icon' => 'dashicons-admin-links'
    ));
});

// 3. Catálogo de Produtos e IDs do WooCommerce Memberships
function get_reiki_products() {
    return array(
        'cuidar' => array(
            'nome' => 'Formação Método CUIDAR',
            'membership_id' => 15098,
            'preco_brl' => 697.00,
            'preco_usd' => 145.00,
            'preco_eur' => 125.00
        ),
        'cer' => array(
            'nome' => 'Formação CER',
            'membership_id' => 12219,
            'preco_brl' => 1297.00, // 🎂 ANIVER 02/07 — original: 2197.00
            'preco_usd' => 255.00,  // original: 425.00
            'preco_eur' => 225.00   // original: 375.00
        ),
        'infinity' => array(
            'nome' => 'Infinity Reiki',
            'membership_id' => 12224,
            'preco_brl' => 67.00,
            'preco_usd' => 17.00,
            'preco_eur' => 17.00
        ),
        'desafio-infinity' => array(
            'nome' => 'Desafio Infinity',
            'membership_id' => 13031,
            'preco_brl' => 67.00,
            'preco_usd' => 17.00,
            'preco_eur' => 17.00
        ),
        'reiki-florescer' => array(
            'nome' => 'Reiki Florescer',
            'membership_id' => 12226,
            'preco_brl' => 97.00,   // 🎂 ANIVER 02/07 — original: 297.00
            'preco_usd' => 20.00,   // original: 60.00
            'preco_eur' => 18.00    // original: 55.00
        ),
        'guardias' => array(
            'nome' => 'Guardiãs do Clã',
            'membership_id' => 13180,
            'preco_brl' => 597.00,  // 🎂 ANIVER 02/07 — original: 997.00
            'preco_usd' => 120.00,  // original: 200.00
            'preco_eur' => 105.00   // original: 175.00
        ),
        'mandalas-reiki' => array(
            'nome' => 'Mandalas de Reiki',
            'membership_id' => 12229,
            'preco_brl' => 29.90,   // 🎂 ANIVER 02/07 — original: 197.00
            'preco_usd' => 6.00,    // original: 40.00
            'preco_eur' => 6.00     // original: 35.00
        ),
        'reiki-cristais' => array(
            'nome' => 'Reiki e Cristais',
            'membership_id' => 12221,
            'preco_brl' => 47.00,   // 🎂 ANIVER — preço regular: 597.00
            'preco_usd' => 10.00,
            'preco_eur' => 9.00
        ),
        'masterclass-chakras' => array(
            'nome' => 'Masterclass Chakras Avançados',
            'membership_id' => 12225,
            'preco_brl' => 29.90,   // 🎂 ANIVER — preço regular: 197.00
            'preco_usd' => 6.00,
            'preco_eur' => 6.00
        ),
        'cla-do-livro' => array(
            'nome' => 'O Clã do Livro',
            'membership_id' => 12227,
            'preco_brl' => 97.00,   // 🎂 ANIVER — preço regular: 297.00 (acesso até 31/12/26)
            'preco_usd' => 20.00,
            'preco_eur' => 18.00
        ),
        'negocio-magnetico' => array(
            'nome' => 'Negócio Magnético',
            'membership_id' => 12722,
            'preco_brl' => 29.90,   // 🎂 ANIVER — preço regular: 97.00 (3 meses de acesso)
            'preco_usd' => 6.00,
            'preco_eur' => 6.00
        ),
        'ebook' => array(
            'nome' => 'Ebook',
            'membership_id' => 0, // Ajuste para o ID caso exista, ou 0
            'preco_brl' => 29.90,
            'preco_usd' => 6.00,
            'preco_eur' => 6.00
        )
    );
}

// =========================================================================
// FONTE ÚNICA: juros e order bumps (consumidos pelo checkout via /catalog)
// =========================================================================
function get_reiki_interest_rates() {
    // Tabela OFICIAL de juros do parcelamento (cartão BRL). Única no backend.
    return array(1 => 0, 2 => 5.3, 3 => 7.1, 4 => 9.0, 5 => 10.9, 6 => 12.8, 7 => 14.7, 8 => 16.7, 9 => 18.7, 10 => 20.2, 11 => 22.2, 12 => 24.1);
}

function get_reiki_bumps() {
    return array(
        '12224_ext' => array('nome' => '+6 Meses', 'brl' => 19.90, 'usd' => 5.00, 'eur' => 5.00),
        '13031'     => array('nome' => 'Desafio Infinity', 'brl' => 47.00, 'usd' => 10.00, 'eur' => 10.00),
        '12895'     => array('nome' => 'Deusa AI PRO', 'brl' => 29.90, 'usd' => 6.00, 'eur' => 6.00)
    );
}

// =========================================================================
// ROTA /catalog — fonte única de preços/juros/bumps para os frontends
// =========================================================================
function reiki_catalog_api( WP_REST_Request $request ) {
    $allowed = array(
        'https://checkout.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev',
        'https://app.reikitimeacademy.com.br',
        'http://localhost:5173',
        'http://localhost:3000'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed)) header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Cache-Control: public, max-age=120"); // preços mudam raramente
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );

    // Só expõe o que o frontend precisa (sem membership_id interno)
    $produtos = get_reiki_products();
    $out = array();
    foreach ($produtos as $id => $p) {
        $out[$id] = array(
            'nome'      => $p['nome'],
            'preco_brl' => floatval($p['preco_brl']),
            'preco_usd' => floatval($p['preco_usd']),
            'preco_eur' => floatval($p['preco_eur'])
        );
    }

    return rest_ensure_response( array(
        'version'        => 'estimado-1', // marcador p/ confirmar qual versão do backend está no ar
        'products'       => $out,
        'interest_rates' => get_reiki_interest_rates(),
        'bumps'          => get_reiki_bumps()
    ) );
}

// Status das páginas de vendas — o PWA liga/desliga e as LPs leem e redirecionam.
// active (padrão) | off (-> ajuda) | espera (-> vagas-esgotadas)
function reiki_page_status_api( WP_REST_Request $request ) {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin && (strpos($origin, 'reikitimeacademy.com.br') !== false || strpos($origin, 'pages.dev') !== false || strpos($origin, 'localhost') !== false)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, x-admin-token, x-dashboard-pin");
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );

    $store = get_option('reiki_page_status', array());
    if (!is_array($store)) $store = array();

    if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
        header("Cache-Control: public, max-age=10");
        return rest_ensure_response( array('pages' => (object) $store) );
    }

    // POST -> só admin
    if (!reiki_is_admin($request)) return new WP_Error('nao_autorizado', 'Acesso negado', array('status' => 401));
    $params = $request->get_json_params();
    $page   = sanitize_text_field($params['page'] ?? '');
    $status = sanitize_text_field($params['status'] ?? '');
    if (empty($page) || !in_array($status, array('active', 'off', 'espera'))) {
        return new WP_Error('erro', 'page/status inválidos', array('status' => 400));
    }
    if ($status === 'active') {
        unset($store[$page]);
    } else {
        $store[$page] = $status;
    }
    update_option('reiki_page_status', $store, false); // autoload off
    return rest_ensure_response( array('sucesso' => true, 'pages' => (object) $store) );
}

// "Estimado" (a receber) — boletos pendentes/vencidos do Asaas + assinaturas ativas (Asaas + Stripe).
// SOMENTE LEITURA. É previsão, não dinheiro garantido (boleto pode não pagar, assinatura pode cancelar).
function reiki_estimado_api( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );
    if (!reiki_is_admin($request)) return new WP_Error('nao_autorizado', 'Acesso negado', array('status' => 401));

    $cache = get_transient('reiki_estimado_cache');
    if (is_array($cache)) return rest_ensure_response($cache);

    // --- Asaas: boletos a vencer (PENDING) e vencidos (OVERDUE) ---
    $a_vencer = array('total' => 0.0, 'qtd' => 0);
    $vencidos = array('total' => 0.0, 'qtd' => 0);
    foreach (array('PENDING', 'OVERDUE') as $status) {
        $offset = 0; $loops = 0; $more = true;
        while ($more && $loops < 15) {
            $loops++;
            $r = reiki_asaas_request('GET', '/payments?status=' . $status . '&billingType=BOLETO&limit=100&offset=' . $offset);
            if (is_wp_error($r)) break;
            $b = json_decode(wp_remote_retrieve_body($r), true);
            if (empty($b['data'])) break;
            foreach ($b['data'] as $p) {
                $v = floatval($p['value']);
                if ($status === 'PENDING') { $a_vencer['total'] += $v; $a_vencer['qtd']++; }
                else { $vencidos['total'] += $v; $vencidos['qtd']++; }
            }
            $more = $b['hasMore'] ?? false;
            $offset += 100;
        }
    }

    // --- Asaas: assinaturas ativas (R$/mês) ---
    $asaas_sub = array('total' => 0.0, 'qtd' => 0);
    $sr = reiki_asaas_request('GET', '/subscriptions?status=ACTIVE&limit=100');
    if (!is_wp_error($sr)) {
        $sb = json_decode(wp_remote_retrieve_body($sr), true);
        if (!empty($sb['data'])) foreach ($sb['data'] as $s) {
            if (($s['cycle'] ?? '') === 'MONTHLY') {
                $asaas_sub['total'] += floatval($s['value']);
                $asaas_sub['qtd']++;
            }
        }
    }

    // --- Stripe: assinaturas ativas por moeda (/mês) ---
    $stripe_sub = array();
    $sa = array('headers' => array('Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY));
    $res = wp_remote_get('https://api.stripe.com/v1/subscriptions?status=active&limit=100', $sa);
    if (!is_wp_error($res)) {
        $sd = json_decode(wp_remote_retrieve_body($res), true);
        if (!empty($sd['data'])) foreach ($sd['data'] as $sub) {
            $price = $sub['items']['data'][0]['price'] ?? null;
            if (!$price) continue;
            $cur = strtoupper($price['currency'] ?? '');
            $amt = floatval($price['unit_amount'] ?? 0) / 100;
            if (($price['recurring']['interval'] ?? 'month') === 'year') $amt = $amt / 12;
            if ($cur === '') continue;
            if (!isset($stripe_sub[$cur])) $stripe_sub[$cur] = array('total' => 0.0, 'qtd' => 0);
            $stripe_sub[$cur]['total'] += $amt;
            $stripe_sub[$cur]['qtd']++;
        }
    }

    $out = array(
        'version' => 'estimado-1',
        'asaas' => array(
            'a_vencer'        => $a_vencer,
            'vencidos'        => $vencidos,
            'assinaturas_mes' => $asaas_sub,
        ),
        'stripe' => array('assinaturas' => (object) $stripe_sub),
    );
    set_transient('reiki_estimado_cache', $out, 300);
    return rest_ensure_response($out);
}

// Status de um pagamento (T4.3) — usado pelo checkout pra atualizar a tela quando o PIX/boleto é pago.
// Público, sem PII (só devolve paid/status), com cache curto e rate limit.
function reiki_payment_status_api( WP_REST_Request $request ) {
    $allowed = array('https://checkout.reikitimeacademy.com.br', 'https://reiki-checkout.pages.dev', 'http://localhost:5173');
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed)) header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );

    $payment_id = sanitize_text_field( $request->get_param('payment_id') );
    if (empty($payment_id)) return new WP_Error('erro', 'payment_id ausente', array('status' => 400));

    if (!reiki_admin_rate('paystatus', 200, 600)) {
        return rest_ensure_response(array('paid' => false, 'status' => 'rate_limited'));
    }

    // Cache curto (4s) p/ não martelar o Asaas se houver vários polls/abas
    $cache_key = 'reiki_paystatus_' . md5($payment_id);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return rest_ensure_response($cached);

    $resp = reiki_asaas_request('GET', '/payments/' . $payment_id);
    if (is_wp_error($resp)) return rest_ensure_response(array('paid' => false, 'status' => 'erro'));
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $status = isset($body['status']) ? $body['status'] : '';
    $out = array(
        'paid'   => in_array($status, array('RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH')),
        'status' => $status,
    );
    set_transient($cache_key, $out, 4);
    return rest_ensure_response($out);
}

// =========================================================================
// ROTAS DA API
// =========================================================================
add_action( 'rest_api_init', function () {
    register_rest_route( 'reiki/v1', '/admin-login', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'reiki_admin_login_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/checkout', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'processar_checkout_universal',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/webhook/asaas', array(
        'methods' => 'POST',
        'callback' => 'processar_webhook_asaas',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/webhook/stripe', array(
        'methods' => 'POST',
        'callback' => 'processar_webhook_stripe',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/stripe-intent', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'processar_stripe_intent_criacao',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/coupon', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'validar_cupom_woocommerce',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/catalog', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'reiki_catalog_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/payment-status', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'reiki_payment_status_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/estimado', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'reiki_estimado_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/page-status', array(
        'methods' => 'GET, POST, OPTIONS',
        'callback' => 'reiki_page_status_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/lead', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'processar_lead_checkout',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/waitlist', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'processar_lista_espera',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/dashboard', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'reiki_dashboard_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/delete-lead', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'reiki_dashboard_delete_lead_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/import-history', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'reiki_import_junho_history',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/venda-externa', array(
        'methods' => 'POST, OPTIONS',
        'callback' => 'registrar_venda_externa_api',
        'permission_callback' => '__return_true'
    ) );
    register_rest_route( 'reiki/v1', '/custom-link', array(
        array(
            'methods' => 'POST, OPTIONS',
            'callback' => 'processar_custom_link_criacao',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'GET, OPTIONS',
            'callback' => 'obter_custom_link_detalhes',
            'permission_callback' => '__return_true'
        )
    ) );
    register_rest_route( 'reiki/v1', '/custom-links-list', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'obter_lista_custom_links',
        'permission_callback' => '__return_true'
    ) );
} );

// =========================================================================
// ROTA DE CRIAÇÃO DE LINK CUSTOMIZADO (LINK BUILDER)
// =========================================================================
function processar_custom_link_criacao( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    if (!reiki_is_admin($request)) {
        return new WP_Error( 'nao_autorizado', 'Acesso negado', array( 'status' => 401 ) );
    }

    $params = $request->get_json_params();

    $nome = sanitize_text_field($params['nome_exibicao'] ?? $params['nome'] ?? '');
    $brl = floatval($params['preco_brl'] ?? $params['brl'] ?? 0);
    $usd = floatval($params['preco_usd'] ?? $params['usd'] ?? 0);
    $eur = floatval($params['preco_eur'] ?? $params['eur'] ?? 0);
    $curso_vinculado = sanitize_text_field($params['curso_vinculado'] ?? '');
    $is_carne = isset($params['is_carne']) ? (bool) $params['is_carne'] : false;
    $is_subscription = isset($params['is_subscription']) ? (bool) $params['is_subscription'] : false;
    $parcelas_carne = isset($params['parcelas_carne']) ? intval($params['parcelas_carne']) : 1;

    if (empty($nome) || ($brl <= 0 && $usd <= 0 && $eur <= 0)) {
        return new WP_Error( 'erro_dados', 'Nome e pelo menos 1 Valor são obrigatórios.', array( 'status' => 400 ) );
    }

    // T2.6: piso de preço quando o link LIBERA um curso (anti-fraude caso o token de admin vaze).
    // 25% permite a renovação da CER por R$597 (≈27% de 2197) com folga e bloqueia "CER por R$1".
    // Ajustável aqui (ou via wp-config). Cobrança avulsa (sem curso vinculado) não tem piso.
    if (!defined('REIKI_CUSTOM_LINK_MIN_PCT')) define('REIKI_CUSTOM_LINK_MIN_PCT', 0.25);
    if (!empty($curso_vinculado)) {
        $catalogo = get_reiki_products();
        if (!isset($catalogo[$curso_vinculado])) {
            // Curso vinculado tem que existir no catálogo (senão cobra e não libera acesso)
            return new WP_Error('curso_invalido', 'Curso vinculado não existe no catálogo.', array('status' => 400));
        }
        if (isset($catalogo[$curso_vinculado])) {
            $c = $catalogo[$curso_vinculado];
            $pct = REIKI_CUSTOM_LINK_MIN_PCT;
            $checks = array(
                array('BRL', $brl, floatval($c['preco_brl'] ?? 0)),
                array('USD', $usd, floatval($c['preco_usd'] ?? 0)),
                array('EUR', $eur, floatval($c['preco_eur'] ?? 0)),
            );
            foreach ($checks as $chk) {
                list($moeda, $valor, $preco_catalogo) = $chk;
                if ($valor > 0 && $preco_catalogo > 0) {
                    $minimo = round($preco_catalogo * $pct, 2);
                    if ($valor < $minimo) {
                        return new WP_Error(
                            'preco_abaixo_minimo',
                            sprintf('Preço em %s (%.2f) abaixo do mínimo para o curso "%s": mínimo %s %.2f (%d%% do catálogo). Ajuste o valor ou desvincule o curso.',
                                $moeda, $valor, $c['nome'], $moeda, $minimo, intval($pct * 100)),
                            array('status' => 400)
                        );
                    }
                }
            }
        }
    }

    $post_id = wp_insert_post(array(
        'post_title' => 'Link: ' . $nome,
        'post_type' => 'reiki_custom_link',
        'post_status' => 'publish'
    ));

    if (is_wp_error($post_id)) {
        return new WP_Error( 'erro_db', 'Falha ao salvar link no banco.', array( 'status' => 500 ) );
    }

    update_post_meta($post_id, 'preco_brl', $brl);
    update_post_meta($post_id, 'preco_usd', $usd);
    update_post_meta($post_id, 'preco_eur', $eur);
    update_post_meta($post_id, 'nome_exibicao', $nome);
    if (!empty($curso_vinculado)) {
        update_post_meta($post_id, 'curso_vinculado', $curso_vinculado);
    }
    if ($is_carne) {
        update_post_meta($post_id, 'is_carne', '1');
        update_post_meta($post_id, 'parcelas_carne', $parcelas_carne);
    }
    if ($is_subscription) {
        update_post_meta($post_id, 'is_subscription', '1');
        update_post_meta($post_id, 'parcelas_subscription', $parcelas_carne); // Reutilizamos o campo parcelas
    }

    return rest_ensure_response(array(
        'sucesso' => true,
        'link_id' => 'custom_' . $post_id
    ));
}

function obter_custom_link_detalhes( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://checkout.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    $id = $request->get_param('id');
    if (empty($id) || strpos($id, 'custom_') !== 0) {
        return new WP_Error( 'id_invalido', 'ID inválido.', array( 'status' => 400 ) );
    }

    $post_id = intval(str_replace('custom_', '', $id));
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'reiki_custom_link') {
        return new WP_Error( 'nao_encontrado', 'Link não encontrado.', array( 'status' => 404 ) );
    }

    $ps = get_option('reiki_page_status', array());
    $link_status = (is_array($ps) && isset($ps[$id])) ? $ps[$id] : 'active';

    return rest_ensure_response(array(
        'sucesso' => true,
        'status' => $link_status,
        'title' => get_post_meta($post_id, 'nome_exibicao', true),
        'subtitle' => 'Cobrança Avulsa Exclusiva',
        'brlPrice' => floatval(get_post_meta($post_id, 'preco_brl', true)),
        'usdPrice' => floatval(get_post_meta($post_id, 'preco_usd', true)),
        'eurPrice' => floatval(get_post_meta($post_id, 'preco_eur', true)),
        'brlOriginal' => floatval(get_post_meta($post_id, 'preco_brl', true)),
        'curso_vinculado' => get_post_meta($post_id, 'curso_vinculado', true),
        'is_carne' => get_post_meta($post_id, 'is_carne', true) === '1',
        'is_subscription' => get_post_meta($post_id, 'is_subscription', true) === '1',
        'parcelas_carne' => intval(get_post_meta($post_id, 'parcelas_carne', true) ?: get_post_meta($post_id, 'parcelas_subscription', true)),
        'image' => 'https://reikitimeacademy.com.br/wp-content/uploads/2026/03/guardias-hero-bg.png'
    ));
}

function obter_lista_custom_links( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    if (!reiki_is_admin($request)) {
        return new WP_Error( 'nao_autorizado', 'Acesso negado', array( 'status' => 401 ) );
    }

    $args = array(
        'post_type' => 'reiki_custom_link',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC'
    );
    $query = new WP_Query($args);
    $links = array();
    $ps = get_option('reiki_page_status', array());
    if (!is_array($ps)) $ps = array();

    foreach ($query->posts as $post) {
        $link_id = 'custom_' . $post->ID;
        $links[] = array(
            'id' => $link_id,
            'status' => isset($ps[$link_id]) ? $ps[$link_id] : 'active',
            'name' => get_post_meta($post->ID, 'nome_exibicao', true) ?: $post->post_title,
            'date' => get_the_date('d/m/Y H:i', $post),
            'link_brl' => 'https://checkout.reikitimeacademy.com.br/?produto=' . $link_id . '&currency=BRL',
            'link_usd' => 'https://checkout.reikitimeacademy.com.br/?produto=' . $link_id . '&currency=USD',
            'link_eur' => 'https://checkout.reikitimeacademy.com.br/?produto=' . $link_id . '&currency=EUR',
            'val_brl' => floatval(get_post_meta($post->ID, 'preco_brl', true)),
            'val_usd' => floatval(get_post_meta($post->ID, 'preco_usd', true)),
            'val_eur' => floatval(get_post_meta($post->ID, 'preco_eur', true)),
            'is_carne' => get_post_meta($post->ID, 'is_carne', true) === '1',
            'is_subscription' => get_post_meta($post->ID, 'is_subscription', true) === '1',
            'parcelas_carne' => intval(get_post_meta($post->ID, 'parcelas_carne', true) ?: get_post_meta($post->ID, 'parcelas_subscription', true))
        );
    }

    return rest_ensure_response(array(
        'sucesso' => true,
        'links' => $links
    ));
}

// =========================================================================
// ROTA DE CUPONS
// =========================================================================
function validar_cupom_woocommerce( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://checkout.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    if (!reiki_admin_rate('coupon', 30, 600)) {
        return new WP_Error( 'rate_limit', 'Muitas tentativas.', array( 'status' => 429 ) );
    }

    if ( !class_exists('WC_Coupon') ) {
        return new WP_Error( 'sem_woo', 'WooCommerce não ativo', array( 'status' => 500 ) );
    }

    $codigo = sanitize_text_field( $request->get_param('code') );
    if ( empty($codigo) ) {
        return new WP_Error( 'erro_codigo', 'Código não fornecido', array( 'status' => 400 ) );
    }

    $coupon = new WC_Coupon( $codigo );
    if ( !$coupon->get_id() ) {
        return new WP_Error( 'invalido', 'Cupom inválido', array( 'status' => 404 ) );
    }

    if ( !$coupon->is_valid() ) {
        return new WP_Error( 'invalido', strip_tags($coupon->get_error_message()), array( 'status' => 400 ) );
    }

    return rest_ensure_response( array(
        'sucesso' => true,
        'codigo' => $coupon->get_code(),
        'tipo' => $coupon->get_discount_type(), 
        'valor' => floatval( $coupon->get_amount() )
    ) );
}

// =========================================================================
// 1. CHECKOUT FRONTEND
// =========================================================================
function processar_checkout_universal( WP_REST_Request $request ) {
    $result = _processar_checkout_universal_internal($request);
    if ( is_wp_error($result) ) {
        $error_msg = $result->get_error_message();
        $error_code = $result->get_error_code();
        
        // Log básico
        error_log('ERRO NO CHECKOUT: ' . $error_msg . ' | Codigo: ' . $error_code);
        $p_err = $request->get_json_params();
        reiki_log_tx('erro', array(
            'gateway'    => is_array($p_err) ? ($p_err['gateway'] ?? '') : '',
            'produto_id' => is_array($p_err) ? ($p_err['produto'] ?? '') : '',
            'email'      => (is_array($p_err) && isset($p_err['email'])) ? sanitize_email($p_err['email']) : '',
            'status'     => $error_code,
            'detail'     => $error_msg,
        ));

        // Lista de erros de negócio/bots que NÃO devem enviar e-mail para o admin
        $erros_ignorados = array('seguranca', 'erro_dados', 'rate_limit', 'recusado', 'invalido', 'erro_codigo', 'erro_parcelas', 'erro_valor', 'id_invalido');
        
        if ( ! in_array( $error_code, $erros_ignorados, true ) ) {
            // Enviar E-mail para o Admin
            $admin_email = get_option('admin_email');
            $subject = '⚠️ ALERTA: Erro no Checkout - Reiki Time Academy';
            $message = "Ocorreu uma falha durante o processamento do checkout.\n\n";
            $message .= "Código do Erro: " . $error_code . "\n";
            $message .= "Mensagem: " . $error_msg . "\n\n";
            
            // Extrair alguns dados do cliente para facilitar o diagnóstico
            $params = $request->get_json_params();
            if ($params) {
                $message .= "Dados da Tentativa:\n";
                $message .= "Nome: " . (isset($params['nome']) ? sanitize_text_field($params['nome']) : 'N/A') . "\n";
                $message .= "Email: " . (isset($params['email']) ? sanitize_email($params['email']) : 'N/A') . "\n";
                $message .= "Método: " . (isset($params['payment_method']) ? sanitize_text_field($params['payment_method']) : 'N/A') . "\n";
                $message .= "Gateway: " . (isset($params['currency']) && $params['currency'] === 'BRL' ? 'Asaas' : 'Stripe') . "\n";
                $message .= "Produto ID: " . (isset($params['produto']) ? sanitize_text_field($params['produto']) : 'N/A') . "\n";
            }
            
            wp_mail($admin_email, $subject, $message);
        }
    }
    return $result;
}

function _processar_checkout_universal_internal( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://checkout.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    $produtos = get_reiki_products();
    $params = $request->get_json_params();

    // 0. Cloudflare Turnstile Validation
    $turnstile_token = isset($params['turnstileToken']) ? sanitize_text_field($params['turnstileToken']) : '';
    if (empty($turnstile_token)) {
        return new WP_Error('seguranca', 'Validação anti-bot ausente.', array('status' => 403));
    }
    
    $turnstile_verify = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
        'body' => array(
            'secret'   => REIKI_TURNSTILE_SECRET_KEY,
            'response' => $turnstile_token,
            'remoteip' => reiki_client_ip()
        )
    ));
    
    if (is_wp_error($turnstile_verify)) {
        return new WP_Error('seguranca', 'Erro de conexão com servidor anti-bot.', array('status' => 500));
    }
    
    $turnstile_body = json_decode(wp_remote_retrieve_body($turnstile_verify), true);
    if (empty($turnstile_body['success'])) {
        return new WP_Error('seguranca', 'Falha na verificação de segurança.', array('status' => 403));
    }

    $gateway = sanitize_text_field( $params['gateway'] );
    $nome = sanitize_text_field( $params['nome'] );
    $email = sanitize_email( $params['email'] );
    $produto_id = sanitize_text_field( $params['produto'] );
    $currency = strtolower(sanitize_text_field( $params['currency'] ?? 'brl' ));
    
    // Suporte para Links Customizados (Link Builder)
    $produto_info = null;
    $curso_vinculado_id = null;
    if (strpos($produto_id, 'custom_') === 0) {
        $post_id = intval(str_replace('custom_', '', $produto_id));
        $post = get_post($post_id);
        if ($post && $post->post_type === 'reiki_custom_link') {
            $produto_info = array(
                'nome' => get_post_meta($post_id, 'nome_exibicao', true) ?: $post->post_title,
                'membership_id' => 0, // Por enquanto tratamos apenas o curso principal pela engine do checkout
                'preco_brl' => floatval(get_post_meta($post_id, 'preco_brl', true)),
                'preco_usd' => floatval(get_post_meta($post_id, 'preco_usd', true)),
                'preco_eur' => floatval(get_post_meta($post_id, 'preco_eur', true)),
                'is_subscription' => get_post_meta($post_id, 'is_subscription', true) === '1',
                'parcelas_subscription' => intval(get_post_meta($post_id, 'parcelas_subscription', true))
            );
            $curso_vinculado_id = get_post_meta($post_id, 'curso_vinculado', true);
            if (!empty($curso_vinculado_id) && isset($produtos[$curso_vinculado_id])) {
                // Se tiver curso vinculado, ele recebe o membership do curso
                $produto_info['membership_id'] = $produtos[$curso_vinculado_id]['membership_id'];
            }
        }
    } else {
        $produto_info = isset($produtos[$produto_id]) ? $produtos[$produto_id] : null;
    }

    $bumps_selecionados = isset($params['bumps']) && is_array($params['bumps']) ? array_map('sanitize_text_field', $params['bumps']) : array();
    
    // Configuração dos Bumps
    $bumps_config = get_reiki_bumps();

    $nomes_comprados = array();

    if ($produto_info) {
        $nomes_comprados[] = $produto_info['nome'];
        if ($currency === 'usd') {
            $valor_total = $produto_info['preco_usd'];
        } elseif ($currency === 'eur') {
            $valor_total = $produto_info['preco_eur'];
        } else {
            $valor_total = $produto_info['preco_brl'];
        }
    } else {
        $valor_total = 0;
    }

    $cupom_aplicado = sanitize_text_field( $params['cupom'] ?? '' );
    $cupom_id_queimado = 0;

    foreach ($bumps_selecionados as $bump_id) {
        if (isset($bumps_config[$bump_id])) {
            $nomes_comprados[] = $bumps_config[$bump_id]['nome'];
            if ($currency === 'usd') {
                $valor_total += $bumps_config[$bump_id]['usd'];
            } elseif ($currency === 'eur') {
                $valor_total += $bumps_config[$bump_id]['eur'];
            } else {
                $valor_total += $bumps_config[$bump_id]['brl'];
            }
        }
    }

    if ( !empty($cupom_aplicado) && class_exists('WC_Coupon') ) {
        $coupon = new WC_Coupon( $cupom_aplicado );
        if ( $coupon->get_id() && $coupon->is_valid() ) {
            $tipo_desc = $coupon->get_discount_type();
            $valor_desc = floatval( $coupon->get_amount() );
            if ( $tipo_desc === 'percent' ) {
                $valor_total = $valor_total - ($valor_total * ($valor_desc / 100));
            } else {
                // Fixed discounts are only safe to apply exactly in the base currency (BRL). 
                // If it's USD or EUR, applying a fixed BRL number as USD is dangerous, 
                // so we only apply it if the currency is BRL or if you want to force it.
                if ( $currency === 'brl' ) {
                    $valor_total = $valor_total - $valor_desc;
                } else {
                    // Pra moedas estrangeiras, cupons fixos não rolam por segurança de conversão, só em porcentagem.
                    // Vamos ignorar silenciosamente.
                }
            }
            if ($valor_total < 0) $valor_total = 0;
            $cupom_id_queimado = $coupon->get_id();
        }
    }

    $descricao_compra = 'Compra: ' . implode(', ', $nomes_comprados);

    if ( empty($nome) || empty($email) || empty($produto_id) ) {
        return new WP_Error( 'erro_dados', 'Preencha todos os dados obrigatórios', array( 'status' => 400 ) );
    }

    // --- RATE LIMITING ---
    $ip_cliente = reiki_client_ip();
    $transient_name = 'rate_limit_' . md5($ip_cliente);
    $tentativas = get_transient( $transient_name ) ?: 0;
    if ( $tentativas > 5 ) return new WP_Error( 'rate_limit', 'Muitas tentativas.', array( 'status' => 429 ) );
    set_transient( $transient_name, $tentativas + 1, 60 * 15 );

    if ( empty( $produto_info ) ) {
        return new WP_Error( 'erro_produto', 'Produto não encontrado', array( 'status' => 400 ) );
    }

    $wp_user_id = criar_usuario_silencioso( $nome, $email );
    if ( is_wp_error( $wp_user_id ) ) {
        return new WP_Error( 'erro_usuario', 'Não foi possível criar a conta do aluno.', array( 'status' => 500 ) );
    }

    $retorno = array('sucesso' => false);

    // ================== FLUXO ASAAS (BRASIL) ==================
    if ( $gateway === 'asaas' ) {
        $cpf = sanitize_text_field( $params['cpf'] );
        $telefone = sanitize_text_field( $params['telefone'] );
        $metodo = sanitize_text_field( $params['metodo'] );
        $cep = sanitize_text_field( $params['cep'] ?? '' );
        $numero = sanitize_text_field( $params['numero'] ?? '' );

        $base_url = ASAAS_IS_SANDBOX ? 'https://sandbox.asaas.com/api/v3' : 'https://api.asaas.com/v3';
        $headers = array('Content-Type' => 'application/json', 'access_token' => REIKI_ASAAS_API_KEY);

        $customer_id = buscar_ou_criar_cliente_asaas($nome, $email, $cpf, $telefone, $base_url, $headers);
        if ( is_wp_error( $customer_id ) ) return $customer_id;
        
        $descricao_compra_final = $descricao_compra;
        $external_ref_base = $wp_user_id . '|' . $produto_id . '|' . implode(',', $bumps_selecionados) . '|' . $cupom_id_queimado;
        
        $retorno['sucesso'] = true;
        $retorno['metodo'] = $metodo;

        if ($metodo === 'two_cards') {
            $cards = $params['cards'];
            if (count($cards) !== 2) return new WP_Error('erro_dados', 'Dados dos dois cartões incompletos.', array('status' => 400));
            
            $soma_cartoes = floatval($cards[0]['value']) + floatval($cards[1]['value']);
            if (abs($soma_cartoes - $valor_total) > 0.05) {
                return new WP_Error('erro_valor', 'A soma dos cartões não confere com o valor total.', array('status' => 400));
            }
            
            // Cartão 1
            $c1_data = reiki_asaas_montar_cartao($cards[0], $cards[0]['value'], $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero);
            $resp1 = reiki_asaas_cobranca($customer_id, 'CREDIT_CARD', $cards[0]['value'], date('Y-m-d'), $descricao_compra_final . ' (Cartão 1/2)', $external_ref_base, $c1_data, true);
            if (is_wp_error($resp1)) return $resp1;
            
            // Cartão 2
            $c2_data = reiki_asaas_montar_cartao($cards[1], $cards[1]['value'], $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero);
            $resp2 = reiki_asaas_cobranca($customer_id, 'CREDIT_CARD', $cards[1]['value'], date('Y-m-d'), $descricao_compra_final . ' (Cartão 2/2)', $external_ref_base, $c2_data, true);
            
            if (is_wp_error($resp2)) {
                reiki_asaas_cancelar($resp1['id']);
                return $resp2;
            }
            
            // Ambos autorizados! Captura os dois!
            $cap1 = reiki_asaas_capturar($resp1['id']);
            $cap2 = reiki_asaas_capturar($resp2['id']);
            
            if (is_wp_error($cap1) || is_wp_error($cap2)) {
                 reiki_asaas_cancelar($resp1['id']);
                 reiki_asaas_cancelar($resp2['id']);
                 return new WP_Error('erro_captura', 'Falha na captura de um dos cartões. Tente novamente.', array('status' => 500));
            }
            
            $retorno['status_venda'] = 'CONFIRMED';
            
            conceder_acesso_curso($wp_user_id, $produto_id, '2 Cartões de Crédito (Asaas)', null, $valor_total);
            if (!empty($bumps_selecionados)) processar_acesso_bumps($wp_user_id, $bumps_selecionados);
            if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) wc_update_coupon_usage_counts( $cupom_id_queimado );
            
        } elseif ($metodo === 'pix_and_card') {
            $pix_val = floatval($params['pix_value']);
            $card = $params['card'];
            
            $soma_pix_cartao = $pix_val + floatval($card['value']);
            if (abs($soma_pix_cartao - $valor_total) > 0.05) {
                return new WP_Error('erro_valor', 'A soma do PIX e Cartão não confere com o valor total.', array('status' => 400));
            }
            
            // Cartão primeiro (Authorize Only)
            $c_data = reiki_asaas_montar_cartao($card, $card['value'], $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero);
            $resp_card = reiki_asaas_cobranca($customer_id, 'CREDIT_CARD', $card['value'], date('Y-m-d'), $descricao_compra_final . ' (Parte Cartão)', $external_ref_base, $c_data, true);
            if (is_wp_error($resp_card)) return $resp_card; // Falhou o cartão, nem gera o PIX
            
            // NOVO: Agenda a verificação de segurança em 30 min (garante que o limite seja estornado se tudo falhar)
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 1800, 'reiki_verificar_cartao_preso', array($resp_card['id']));
            }
            
            // Cartão pré-autorizado! Esconde o ID do Cartão na externalReference do PIX
            $pix_external_ref = $external_ref_base . '|' . $resp_card['id']; 
            
            $resp_pix = reiki_asaas_cobranca($customer_id, 'PIX', $pix_val, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Parte PIX)', $pix_external_ref);
            if (is_wp_error($resp_pix)) {
                reiki_asaas_cancelar($resp_card['id']);
                return $resp_pix;
            }
            
            $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp_pix['id'] . '/pixQrCode', array('headers' => $headers) );
            if (!is_wp_error($pix_qr)) {
                $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
                if (isset($pix_data['encodedImage'])) $retorno['pix_qrcode'] = $pix_data['encodedImage'];
                if (isset($pix_data['payload'])) $retorno['pix_copia_cola'] = $pix_data['payload'];
            }
            $retorno['status_venda'] = 'PENDING';

        } elseif ($metodo === 'pix_and_boleto') {
            $pix_val = floatval($params['pix_value']);
            $boleto_val = $valor_total - $pix_val;
            
            if ($boleto_val <= 0 || $pix_val <= 0 || abs(($pix_val + $boleto_val) - $valor_total) > 0.05) {
                return new WP_Error('erro_valor', 'Valores de Pix e Boleto inválidos.', array('status' => 400));
            }
            
            // Criação da cobrança do BOLETO
            // Colocamos o external_ref no boleto. Isso é o que vai disparar o acesso!
            $resp_boleto = reiki_asaas_cobranca($customer_id, 'BOLETO', $boleto_val, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Parte Boleto)', $external_ref_base);
            if (is_wp_error($resp_boleto)) return $resp_boleto; 
            
            // Criação da cobrança do PIX
            // O PIX ganha um sufixo especial para NÃO liberar acesso isoladamente.
            $pix_external_ref = $external_ref_base . '|BOLETO:' . $resp_boleto['id'];
            
            $resp_pix = reiki_asaas_cobranca($customer_id, 'PIX', $pix_val, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Entrada PIX)', $pix_external_ref);
            if (is_wp_error($resp_pix)) {
                reiki_asaas_cancelar($resp_boleto['id']);
                return $resp_pix;
            }
            
            $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp_pix['id'] . '/pixQrCode', array('headers' => $headers) );
            if (!is_wp_error($pix_qr)) {
                $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
                if (isset($pix_data['encodedImage'])) $retorno['pix_qrcode'] = $pix_data['encodedImage'];
                if (isset($pix_data['payload'])) $retorno['pix_copia_cola'] = $pix_data['payload'];
            }
            $retorno['boleto_url'] = $resp_boleto['bankSlipUrl'];
            $retorno['status_venda'] = 'PENDING';

        } elseif ($metodo === 'boleto_parcelado') {
            $parcelas = intval($params['parcelas_carne'] ?? 1);
            if ($parcelas < 2 || $parcelas > 12) {
                return new WP_Error('erro_parcelas', 'Número de parcelas inválido para carnê.', array('status' => 400));
            }
            
            // Criar carnê na Asaas (Instalments)
            // IMPORTANTE: Adicionamos o prefixo CARNE: no external_ref para o webhook identificar
            $carne_external_ref = 'CARNE:' . $external_ref_base;
            $resp_carne = reiki_asaas_cobranca_carne($customer_id, $valor_total, $parcelas, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Carnê ' . $parcelas . 'x)', $carne_external_ref);
            
            if (is_wp_error($resp_carne)) return $resp_carne;
            
            // Tentar pegar a URL do carnê (capa do carnê) ou do primeiro boleto
            if (!empty($resp_carne['paymentBookUrl'])) {
                $retorno['boleto_url'] = $resp_carne['paymentBookUrl'];
            }
            // Além disso, vamos forçar pegar o primeiro boleto para a pessoa já baixar
            if (!empty($resp_carne['id'])) {
                $payments_resp = wp_remote_get( $base_url . '/payments?installment=' . $resp_carne['id'] . '&limit=50', array('headers' => $headers) );
                if (!is_wp_error($payments_resp)) {
                    $payments_data = json_decode(wp_remote_retrieve_body($payments_resp), true);
                    if (!empty($payments_data['data'])) {
                        $primeiro_boleto_url = '';
                        $parcelas_arr = $payments_data['data'];
                        // Ordenar por dueDate para garantir que peguemos a primeira parcela
                        usort($parcelas_arr, function($a, $b) {
                            return strtotime($a['dueDate']) - strtotime($b['dueDate']);
                        });
                        
                        foreach ($parcelas_arr as $p) {
                            if (!empty($p['bankSlipUrl'])) {
                                $primeiro_boleto_url = $p['bankSlipUrl'];
                                break;
                            }
                        }
                        
                        if (!empty($primeiro_boleto_url)) {
                            $retorno['boleto_url'] = $primeiro_boleto_url;
                        }
                    }
                }
            }
            $retorno['status_venda'] = 'PENDING';

        } else {
            // Fluxo NORMAL ou ASSINATURA (Cartão)
            $billing_type = strtoupper($metodo);
            $cartao_extra = null;
            $due_date = date('Y-m-d', strtotime('+2 days'));
            if ($billing_type === 'PIX') $due_date = date('Y-m-d', strtotime('+1 days'));
            
            if ($billing_type === 'CREDIT_CARD') {
                $due_date = date('Y-m-d');
                $params['parcelas'] = intval($params['parcelas'] ?? 1); // fallback
                $cartao_extra = reiki_asaas_montar_cartao($params, $valor_total, $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero);
            }
            
            $is_assinatura = !empty($produto_info['is_subscription']) && $produto_info['is_subscription'] && $billing_type === 'CREDIT_CARD';
            
            if ($is_assinatura) {
                $parcelas_ass = intval($produto_info['parcelas_subscription'] ?? 1);
                if ($parcelas_ass < 2) $parcelas_ass = 2;
                $valor_mensal = round($valor_total / $parcelas_ass, 2);
                $assinatura_external_ref = 'ASSINATURA:' . $external_ref_base;
                $resp = reiki_asaas_assinatura($customer_id, $valor_mensal, $parcelas_ass, $due_date, $descricao_compra_final . ' (Assinatura ' . $parcelas_ass . 'x)', $assinatura_external_ref, $cartao_extra);
            } else {
                $resp = reiki_asaas_cobranca($customer_id, $billing_type, $valor_total, $due_date, $descricao_compra_final, $external_ref_base, $cartao_extra, false);
            }
            
            if (is_wp_error($resp)) return $resp;

            $retorno['status_venda'] = $resp['status'] ?? 'PENDING';
            // payment_id p/ o frontend acompanhar o status (T4.3) — só PIX/boleto fazem sentido aqui
            if (in_array($billing_type, array('PIX', 'BOLETO')) && !empty($resp['id'])) {
                $retorno['payment_id'] = $resp['id'];
            }

            if ($billing_type == 'PIX' && !$is_assinatura) {
                $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp['id'] . '/pixQrCode', array('headers' => $headers) );
                if (!is_wp_error($pix_qr)) {
                    $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
                    if (isset($pix_data['encodedImage'])) $retorno['pix_qrcode'] = $pix_data['encodedImage'];
                    if (isset($pix_data['payload'])) $retorno['pix_copia_cola'] = $pix_data['payload'];
                }
            }

            // Boleto simples: devolve o link do boleto p/ o cliente acessar (Asaas não envia por e-mail).
            // Usa bankSlipUrl (PDF do boleto) ou, na falta, invoiceUrl (página hospedada do Asaas).
            if ($billing_type == 'BOLETO') {
                if (!empty($resp['bankSlipUrl'])) {
                    $retorno['boleto_url'] = $resp['bankSlipUrl'];
                } elseif (!empty($resp['invoiceUrl'])) {
                    $retorno['boleto_url'] = $resp['invoiceUrl'];
                }
            }

            if ( in_array($resp['status'], array('CONFIRMED', 'RECEIVED', 'ACTIVE')) ) {
                $nome_metodo = ($billing_type === 'CREDIT_CARD') ? ($is_assinatura ? 'Assinatura Crédito (Asaas)' : 'Cartão de Crédito (Asaas)') : (($billing_type === 'PIX') ? 'PIX (Asaas)' : 'Boleto (Asaas)');
                conceder_acesso_curso($wp_user_id, $produto_id, $nome_metodo, null, $valor_total, true, ($resp['id'] ?? ''), 'asaas');
                if (!empty($bumps_selecionados)) processar_acesso_bumps($wp_user_id, $bumps_selecionados);
                if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) wc_update_coupon_usage_counts( $cupom_id_queimado );
            }
        }

    // ================== FLUXO STRIPE (INTERNACIONAL NOVO) ==================
    } else if ( $gateway === 'stripe_update' ) {
        $payment_intent_id = sanitize_text_field( $params['payment_intent_id'] );
        if (empty($payment_intent_id)) return new WP_Error('erro', 'Intent ID missing', array('status'=>400));

        $stripe_headers = array(
            'Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY,
            'Content-Type'  => 'application/x-www-form-urlencoded'
        );

        $is_setup_intent = strpos($payment_intent_id, 'seti_') === 0;

        if ($is_setup_intent) {
            // 1. O SetupIntent JÁ deve ter sido confirmado no frontend. Valida antes de criar a assinatura.
            $setup_intent_resp = wp_remote_get('https://api.stripe.com/v1/setup_intents/' . $payment_intent_id, array('headers' => $stripe_headers));
            $setup_intent_data = is_wp_error($setup_intent_resp) ? null : json_decode(wp_remote_retrieve_body($setup_intent_resp), true);
            if (($setup_intent_data['status'] ?? '') !== 'succeeded' || empty($setup_intent_data['payment_method'])) {
                return new WP_Error('setup_invalido', 'Cartão ainda não confirmado.', array('status' => 400));
            }
            $payment_method_id = $setup_intent_data['payment_method'];

            // 2. Criar Cliente
            $customer_body = http_build_query(array(
                'name' => $nome,
                'email' => $email,
                'payment_method' => $payment_method_id,
                'invoice_settings[default_payment_method]' => $payment_method_id
            ));
            $customer_resp = wp_remote_post('https://api.stripe.com/v1/customers', array('headers' => $stripe_headers, 'body' => $customer_body, 'timeout' => 30));
            $customer_data = is_wp_error($customer_resp) ? null : json_decode(wp_remote_retrieve_body($customer_resp), true);
            if (empty($customer_data['id'])) return new WP_Error('erro_stripe', 'Falha ao criar cliente na Stripe.', array('status' => 502));
            $customer_id = $customer_data['id'];

            // 3. Criar Produto
            $prod_body = http_build_query(array('name' => $descricao_compra));
            $prod_resp = wp_remote_post('https://api.stripe.com/v1/products', array('headers' => $stripe_headers, 'body' => $prod_body, 'timeout' => 30));
            $prod_data = is_wp_error($prod_resp) ? null : json_decode(wp_remote_retrieve_body($prod_resp), true);
            if (empty($prod_data['id'])) return new WP_Error('erro_stripe', 'Falha ao criar produto na Stripe.', array('status' => 502));
            $prod_id = $prod_data['id'];

            // 4. Criar Price (mensal)
            $parcelas_ass = intval($produto_info['parcelas_subscription'] ?? 1);
            if ($parcelas_ass < 2) $parcelas_ass = 2;
            $valor_mensal = round($valor_total / $parcelas_ass, 2);
            $valor_mensal_cents = intval(round($valor_mensal * 100));
            $price_body = http_build_query(array(
                'product' => $prod_id,
                'currency' => strtolower($currency),
                'unit_amount' => $valor_mensal_cents,
                'recurring[interval]' => 'month'
            ));
            $price_resp = wp_remote_post('https://api.stripe.com/v1/prices', array('headers' => $stripe_headers, 'body' => $price_body, 'timeout' => 30));
            $price_data = is_wp_error($price_resp) ? null : json_decode(wp_remote_retrieve_body($price_resp), true);
            if (empty($price_data['id'])) return new WP_Error('erro_stripe', 'Falha ao criar preço na Stripe.', array('status' => 502));
            $price_id = $price_data['id'];

            // 5. Criar Subscription (com amount_esperado_mensal p/ o webhook validar o valor da fatura)
            $sub_body = http_build_query(array(
                'customer' => $customer_id,
                'items[0][price]' => $price_id,
                'metadata[wp_user_id]' => $wp_user_id,
                'metadata[produto_id]' => $produto_id,
                'metadata[bumps]' => implode(',', $bumps_selecionados),
                'metadata[cupom_id]' => $cupom_id_queimado,
                'metadata[amount_esperado_mensal]' => $valor_mensal_cents
            ));
            $response = wp_remote_post('https://api.stripe.com/v1/subscriptions', array('headers' => $stripe_headers, 'body' => $sub_body, 'timeout' => 30));
            $sub_data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
            if (empty($sub_data['id'])) return new WP_Error('erro_stripe', 'Falha ao criar assinatura na Stripe.', array('status' => 502));
        } else {
            $stripe_body = http_build_query(array(
                'description' => $descricao_compra,
                'receipt_email' => $email,
                'metadata[wp_user_id]' => $wp_user_id
            ));

            $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
                'headers' => $stripe_headers,
                'body'    => $stripe_body,
                'timeout' => 30
            ) );
        }

        if ( is_wp_error( $response ) ) return new WP_Error( 'erro_api', 'Falha.', array( 'status' => 500 ) );
        
        $retorno['sucesso'] = true;
        $retorno['wp_user_id'] = $wp_user_id;

    // ================== FLUXO STRIPE (INTERNACIONAL LEGADO) ==================
    } else if ( $gateway === 'stripe' ) {
        
        $payment_method_id = sanitize_text_field( $params['stripe_payment_method'] );
        $currency = strtolower(sanitize_text_field( $params['currency'] ));
        $amount_cents = intval( $valor_total * 100 );

        $stripe_headers = array(
            'Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY,
            'Content-Type'  => 'application/x-www-form-urlencoded'
        );

        $stripe_body = http_build_query(array(
            'amount' => $amount_cents,
            'currency' => $currency,
            'payment_method' => $payment_method_id,
            'confirm' => 'true',
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
            'description' => $descricao_compra,
            'receipt_email' => $email,
            'metadata[wp_user_id]' => $wp_user_id,
            'metadata[produto_id]' => $produto_id,
            'metadata[bumps]' => implode(',', $bumps_selecionados),
            'metadata[cupom_id]' => $cupom_id_queimado
        ));

        $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', array(
            'headers' => $stripe_headers,
            'body'    => $stripe_body,
            'timeout' => 30
        ) );

        if ( is_wp_error( $response ) ) return new WP_Error( 'erro_api', 'Falha ao conectar ao Stripe.', array( 'status' => 500 ) );

        $body_resp = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset($body_resp['error']) ) {
            return new WP_Error( 'recusado', $body_resp['error']['message'], array( 'status' => 400 ) );
        }

        if ( $body_resp['status'] === 'succeeded' || $body_resp['status'] === 'requires_capture' ) {
            $retorno['sucesso'] = true;
            $retorno['metodo'] = 'STRIPE';
            conceder_acesso_curso($wp_user_id, $produto_id, 'Cartão de Crédito (Stripe)');
            if (!empty($bumps_selecionados)) {
                processar_acesso_bumps($wp_user_id, $bumps_selecionados);
            }
            if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) {
                wc_update_coupon_usage_counts( $cupom_id_queimado );
            }
        } else if ( $body_resp['status'] === 'requires_action' ) {
            $retorno['sucesso'] = false;
            $retorno['requires_action'] = true;
            $retorno['client_secret'] = $body_resp['client_secret'];
        } else {
            return new WP_Error( 'pendente', 'Pagamento requer ação adicional ou falhou.', array( 'status' => 400 ) );
        }
    } else {
        return new WP_Error( 'erro_gateway', 'Gateway inválido', array( 'status' => 400 ) );
    }

    return rest_ensure_response( $retorno );
}


// =========================================================================
// 2. WEBHOOK DO ASAAS
// =========================================================================
function processar_webhook_asaas( WP_REST_Request $request ) {
    $token_enviado = (string) $request->get_header('asaas-access-token');

    // SEGURANÇA: Rejeita se o token não estiver configurado no servidor ou se o enviado não bater
    if ( !defined('REIKI_ASAAS_WEBHOOK_TOKEN') || REIKI_ASAAS_WEBHOOK_TOKEN === '' || !hash_equals(REIKI_ASAAS_WEBHOOK_TOKEN, $token_enviado) ) {
        return new WP_Error( 'nao_autorizado', 'Token de Webhook inválido ou não configurado', array( 'status' => 401 ) );
    }

    $payload = $request->get_json_params();
    reiki_log_tx('webhook', array(
        'gateway'    => 'asaas',
        'status'     => $payload['event'] ?? '',
        'payment_id' => $payload['payment']['id'] ?? '',
        'amount'     => isset($payload['payment']['value']) ? floatval($payload['payment']['value']) : 0,
    ));

    if ( isset($payload['event']) && in_array($payload['event'], array('PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED')) ) {
        $payment = $payload['payment'];
        if ( !empty($payment['externalReference']) ) {
            $ext_ref = $payment['externalReference'];
            $is_carne = strpos($ext_ref, 'CARNE:') === 0;
            $is_assinatura = strpos($ext_ref, 'ASSINATURA:') === 0;
            
            if ($is_carne) $ext_ref = str_replace('CARNE:', '', $ext_ref);
            if ($is_assinatura) $ext_ref = str_replace('ASSINATURA:', '', $ext_ref);
            
            $parts = explode('|', $ext_ref);
            if ( count($parts) >= 2 ) {
                $wp_user_id = intval($parts[0]);
                
                // Idempotência
                $payment_id = $payment['id'];
                if (get_user_meta( $wp_user_id, '_asaas_processed_' . $payment_id, true )) {
                    return rest_ensure_response( array('recebido' => true, 'msg' => 'Idempotência: Evento já processado.') );
                }
                update_user_meta( $wp_user_id, '_asaas_processed_' . $payment_id, true );

                $produto_id = sanitize_text_field($parts[1]);
                
                // Pagamento Híbrido: Captura o cartão se existir ID atrelado
                if (isset($parts[4]) && !empty($parts[4])) {
                    $target_id = sanitize_text_field($parts[4]);
                    if (strpos($target_id, 'BOLETO:') === 0) {
                        // É o PIX de entrada. NÃO libera acesso. Aguarda o Boleto.
                        return rest_ensure_response( array('recebido' => true, 'msg' => 'Pix de entrada. Acesso aguarda boleto.') );
                    }
                    $cap_result = reiki_asaas_capturar($target_id);
                    if (is_wp_error($cap_result)) {
                        error_log('ALERTA: Captura do cartão ' . $target_id . ' falhou no webhook Pix+Cartão');
                    }
                    if (strpos($target_id, 'BOLETO:') === false) {
                        $nome_metodo = 'Pix + Cartão (Asaas)';
                    } else {
                        $nome_metodo = 'Pix + Boleto (Asaas)';
                    }
                } else {
                    $billingType = isset($payment['billingType']) ? $payment['billingType'] : '';
                    if ($is_assinatura) {
                        $nome_metodo = 'Assinatura Crédito (Asaas)';
                    } elseif ($is_carne) {
                        $nome_metodo = 'Carnê (Asaas)';
                    } else {
                        $nome_metodo = ($billingType === 'CREDIT_CARD') ? 'Cartão de Crédito (Asaas)' : (($billingType === 'PIX') ? 'PIX (Asaas)' : 'Boleto (Asaas)');
                    }
                }

                $valor_pago = isset($payment['value']) ? floatval($payment['value']) : null;
                $registrar_venda = true;

                if (isset($payment['installment']) && !empty($payment['installment'])) {
                    $inst_num = isset($payment['installmentNumber']) ? intval($payment['installmentNumber']) : 1;
                    if ($inst_num > 1) {
                        $registrar_venda = false;
                    } else {
                        // Buscar o valor total do installment plan via API
                        $inst_resp = reiki_asaas_request('GET', '/installments/' . $payment['installment']);
                        if (!is_wp_error($inst_resp)) {
                            $inst_body = json_decode(wp_remote_retrieve_body($inst_resp), true);
                            if (isset($inst_body['value'])) {
                                $valor_pago = floatval($inst_body['value']);
                            }
                        }
                    }
                }

                conceder_acesso_curso($wp_user_id, $produto_id, $nome_metodo, null, $valor_pago, $registrar_venda, ($payment['id'] ?? ''), 'asaas');
                
                if (isset($parts[2]) && !empty($parts[2])) {
                    $bumps_selecionados = explode(',', $parts[2]);
                    processar_acesso_bumps($wp_user_id, $bumps_selecionados);
                }

                if (isset($parts[3]) && !empty($parts[3])) {
                    $cupom_id_queimado = intval($parts[3]);
                    if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) {
                        wc_update_coupon_usage_counts( $cupom_id_queimado );
                    }
                }
            }
        }
    } elseif ( isset($payload['event']) && in_array($payload['event'], array('PAYMENT_OVERDUE', 'PAYMENT_DELETED')) ) {
        $payment = $payload['payment'];
        if ( !empty($payment['externalReference']) ) {
            $ext_ref = $payment['externalReference'];
            $is_carne = strpos($ext_ref, 'CARNE:') === 0;
            $is_assinatura = strpos($ext_ref, 'ASSINATURA:') === 0;
            
            if ($is_carne) $ext_ref = str_replace('CARNE:', '', $ext_ref);
            if ($is_assinatura) $ext_ref = str_replace('ASSINATURA:', '', $ext_ref);
            
            $parts = explode('|', $ext_ref);
            
            // Lógica de Suspensão por Atraso (Boleto Parcelado / Carnê / Assinatura)
            if (($is_carne || $is_assinatura) && count($parts) >= 2 && $payload['event'] === 'PAYMENT_OVERDUE') {
                $wp_user_id = intval($parts[0]);
                $produto_id = sanitize_text_field($parts[1]);
                
                // Idempotência
                $payment_id = $payment['id'];
                if (!get_user_meta( $wp_user_id, '_asaas_overdue_' . $payment_id, true )) {
                    update_user_meta( $wp_user_id, '_asaas_overdue_' . $payment_id, true );
                    
                    if (strpos($produto_id, 'custom_') === 0) {
                        $custom_post_id = intval(str_replace('custom_', '', $produto_id));
                        $curso_vinculado = get_post_meta($custom_post_id, 'curso_vinculado', true);
                        if (!empty($curso_vinculado)) {
                            $produto_id = $curso_vinculado;
                        }
                    }
                    
                    $produtos = get_reiki_products();
                    if (isset($produtos[$produto_id]) && isset($produtos[$produto_id]['membership_id'])) {
                        $membership_id = $produtos[$produto_id]['membership_id'];
                        if (function_exists('wc_memberships_get_user_membership') && $membership_id > 0) {
                            $user_membership = wc_memberships_get_user_membership( $wp_user_id, $membership_id );
                            if ( $user_membership && $user_membership->get_status() === 'active' ) {
                                $user_membership->update_status( 'paused' );
                                error_log("Boleto atrasado! Acesso pausado para o usuário $wp_user_id no produto $produto_id");
                            }
                        }
                    }
                }
            }

            // Pagamento Híbrido: Se o PIX expirar, cancela o hold do cartão
            if ( $payment['billingType'] === 'PIX' && count($parts) >= 5 ) {
                $target_id = sanitize_text_field($parts[4]);
                if (strpos($target_id, 'BOLETO:') === 0) {
                    $boleto_id = str_replace('BOLETO:', '', $target_id);
                    reiki_asaas_cancelar($boleto_id);
                    error_log('Webhook Híbrido: PIX expirado. Boleto vinculado ' . $boleto_id . ' cancelado.');
                } else {
                    reiki_asaas_cancelar($target_id);
                    error_log('Webhook Híbrido: PIX expirado. Autorização do cartão ' . $target_id . ' cancelada.');
                }
            }
        }
    }

    return rest_ensure_response( array('recebido' => true) );
}


// =========================================================================
// 3. WEBHOOK DO STRIPE
// =========================================================================
function processar_webhook_stripe( WP_REST_Request $request ) {
    $payload_raw = $request->get_body();
    $sig_header = $request->get_header('stripe-signature');
    $endpoint_secret = defined('REIKI_STRIPE_WEBHOOK_SECRET') ? REIKI_STRIPE_WEBHOOK_SECRET : '';

    // SEGURANÇA: Exige chave de assinatura do Stripe configurada e presente no header
    if ( empty($endpoint_secret) || empty($sig_header) ) {
        return new WP_Error( 'nao_autorizado', 'Assinatura Stripe não configurada ou ausente', array( 'status' => 401 ) );
    }

    // Validação da assinatura manual (não depende do SDK do Stripe instalado)
    $parts = explode(',', $sig_header);
    $timestamp = '';
    $signatures = array();
    foreach ($parts as $part) {
        $p = explode('=', trim($part), 2);
        if (count($p) == 2) {
            if ($p[0] === 't') $timestamp = $p[1];
            if ($p[0] === 'v1') $signatures[] = $p[1];
        }
    }

    if ( empty($timestamp) || empty($signatures) ) {
        return new WP_Error( 'assinatura_invalida', 'Formato de assinatura inválido', array( 'status' => 401 ) );
    }

    $signed_payload = $timestamp . '.' . $payload_raw;
    $expected_sig = hash_hmac('sha256', $signed_payload, $endpoint_secret);
    
    $sig_match = false;
    foreach ($signatures as $sig) {
        if (hash_equals($expected_sig, $sig)) {
            $sig_match = true;
            break;
        }
    }
    
    if (!$sig_match) {
        return rest_ensure_response( array('recebido' => true, 'msg' => 'Assinatura Stripe Invalida.') );
    }

    if (abs(time() - intval($timestamp)) > 300) {
        return new WP_Error('erro_api', 'Timestamp fora da tolerância (Replay Attack)', array('status' => 401));
    }

    $payload = json_decode($payload_raw, true);
    reiki_log_tx('webhook', array(
        'gateway'    => 'stripe',
        'status'     => $payload['type'] ?? '',
        'payment_id' => $payload['data']['object']['id'] ?? '',
    ));

    if ( isset($payload['type']) && $payload['type'] === 'payment_intent.succeeded' ) {
        $payment_intent = $payload['data']['object'];
        if ( !empty($payment_intent['metadata']['wp_user_id']) && !empty($payment_intent['metadata']['produto_id']) ) {
            $wp_user_id = intval($payment_intent['metadata']['wp_user_id']);
            
            // Idempotência
            $payment_id = $payment_intent['id'];
            if (get_user_meta( $wp_user_id, '_stripe_processed_' . $payment_id, true )) {
                return rest_ensure_response( array('recebido' => true, 'msg' => 'Idempotência: Evento já processado.') );
            }
            update_user_meta( $wp_user_id, '_stripe_processed_' . $payment_id, true );

            $produto_id = sanitize_text_field($payment_intent['metadata']['produto_id']);
            $esperado = intval($payment_intent['metadata']['amount_esperado'] ?? 0);
            $pago = intval($payment_intent['amount_received'] ?? 0);

            if ($esperado > 0 && $pago < $esperado) {
                error_log("STRIPE BYPASS TENTATIVA: pago ($pago) < esperado ($esperado) no PI: " . $payment_id);
                return rest_ensure_response( array('recebido' => true, 'msg' => 'valor divergente') );
            }
            
            // Busca o valor líquido (net) da transação na Stripe para salvar em NZD
            $valor_nzd = 0;
            $charge_id = isset($payment_intent['latest_charge']) ? $payment_intent['latest_charge'] : '';
            if ($charge_id) {
                $stripe_args = array('headers' => array('Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY));
                $charge_res = wp_remote_get('https://api.stripe.com/v1/charges/' . $charge_id . '?expand[]=balance_transaction', $stripe_args);
                if (!is_wp_error($charge_res)) {
                    $charge_data = json_decode(wp_remote_retrieve_body($charge_res), true);
                    if (isset($charge_data['balance_transaction']['net'])) {
                        $valor_nzd = $charge_data['balance_transaction']['net'] / 100;
                    }
                }
            }
            
            conceder_acesso_curso($wp_user_id, $produto_id, 'Cartão de Crédito (Stripe)', $valor_nzd > 0 ? $valor_nzd : null, null, true, ($charge_id ?? ''), 'stripe');
            
            if (!empty($payment_intent['metadata']['bumps'])) {
                $bumps_selecionados = explode(',', $payment_intent['metadata']['bumps']);
                processar_acesso_bumps($wp_user_id, $bumps_selecionados);
            }

            if (!empty($payment_intent['metadata']['cupom_id'])) {
                $cupom_id_queimado = intval($payment_intent['metadata']['cupom_id']);
                if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) {
                    wc_update_coupon_usage_counts( $cupom_id_queimado );
                }
            }
        }
    } elseif ( isset($payload['type']) && $payload['type'] === 'invoice.payment_succeeded' ) {
        $invoice = $payload['data']['object'];
        if ( !empty($invoice['subscription']) ) {
            // É uma renovação ou primeiro pagamento de assinatura
            $sub_id = $invoice['subscription'];
            $stripe_args = array('headers' => array('Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY));
            $sub_res = wp_remote_get('https://api.stripe.com/v1/subscriptions/' . $sub_id, $stripe_args);
            if (!is_wp_error($sub_res)) {
                $sub_data = json_decode(wp_remote_retrieve_body($sub_res), true);
                if (!empty($sub_data['metadata']['wp_user_id']) && !empty($sub_data['metadata']['produto_id'])) {
                    $wp_user_id = intval($sub_data['metadata']['wp_user_id']);
                    $produto_id = sanitize_text_field($sub_data['metadata']['produto_id']);

                    // Anti-bypass: confere o valor pago da fatura contra o esperado (assinaturas novas têm o metadado)
                    $esperado_mensal = intval($sub_data['metadata']['amount_esperado_mensal'] ?? 0);
                    $pago_invoice = intval($invoice['amount_paid'] ?? 0);
                    if ($esperado_mensal > 0 && $pago_invoice < $esperado_mensal) {
                        error_log("STRIPE ASSINATURA VALOR DIVERGENTE: pago=$pago_invoice esperado=$esperado_mensal invoice=" . ($invoice['id'] ?? ''));
                        return rest_ensure_response(array('recebido' => true, 'msg' => 'valor divergente'));
                    }

                    // Idempotência
                    $invoice_id = $invoice['id'];
                    if (!get_user_meta($wp_user_id, '_stripe_processed_' . $invoice_id, true)) {
                        update_user_meta($wp_user_id, '_stripe_processed_' . $invoice_id, true);
                        conceder_acesso_curso($wp_user_id, $produto_id, 'Assinatura Crédito (Stripe)', null, null, true, ($invoice['charge'] ?? ''), 'stripe');
                    }
                }
            }
        }
    } elseif ( isset($payload['type']) && ($payload['type'] === 'invoice.payment_failed' || $payload['type'] === 'customer.subscription.deleted') ) {
        $obj = $payload['data']['object'];
        $sub_id = isset($obj['subscription']) ? $obj['subscription'] : (isset($obj['id']) ? $obj['id'] : '');
        if ($sub_id) {
            $stripe_args = array('headers' => array('Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY));
            $sub_res = wp_remote_get('https://api.stripe.com/v1/subscriptions/' . $sub_id, $stripe_args);
            if (!is_wp_error($sub_res)) {
                $sub_data = json_decode(wp_remote_retrieve_body($sub_res), true);
                if (!empty($sub_data['metadata']['wp_user_id']) && !empty($sub_data['metadata']['produto_id'])) {
                    $wp_user_id = intval($sub_data['metadata']['wp_user_id']);
                    $produto_id = sanitize_text_field($sub_data['metadata']['produto_id']);
                    
                    // Remove acesso
                    $produtos = get_reiki_products();
                    $plan_id = 0;
                    if (strpos($produto_id, 'custom_') === 0) {
                        $post_id = intval(str_replace('custom_', '', $produto_id));
                        $curso_vinculado = get_post_meta($post_id, 'curso_vinculado', true);
                        if (!empty($curso_vinculado) && isset($produtos[$curso_vinculado])) {
                            $plan_id = $produtos[$curso_vinculado]['membership_id'];
                        }
                    } else if (isset($produtos[$produto_id])) {
                        $plan_id = $produtos[$produto_id]['membership_id'];
                    }
                    if ($plan_id > 0 && function_exists('wc_memberships_get_user_membership')) {
                        $user_membership = wc_memberships_get_user_membership($wp_user_id, $plan_id);
                        if ($user_membership) {
                            $user_membership->update_status('paused');
                            error_log('Webhook Stripe: Acesso pausado para o usuário ' . $wp_user_id . ' na assinatura ' . $sub_id);
                        }
                    }
                }
            }
        }
    }

    return rest_ensure_response( array('recebido' => true) );
}


// =========================================================================
// FUNÇÕES AUXILIARES
// =========================================================================
function conceder_acesso_curso($wp_user_id, $produto_id, $metodo = 'Checkout', $valor_nzd = null, $valor_brl_override = null, $registrar_venda = true, $gw_id = '', $gw_type = '') {
    $produtos = get_reiki_products();
    reiki_log_tx('acesso_liberado', array(
        'gateway'    => $metodo,
        'wp_user_id' => $wp_user_id,
        'produto_id' => $produto_id,
        'amount'     => $valor_brl_override ?: ($valor_nzd ?: 0),
        'status'     => 'concedido',
    ));
    if ( !function_exists('wc_memberships_create_user_membership') ) {
        error_log('ALERTA REIKI CHECKOUT: Plugin WooCommerce Memberships inativo! Não foi possível conceder acesso ao usuário ID ' . $wp_user_id);
        return;
    }
    
    $plan_id = 0;
    $produto_nome = 'Produto Desconhecido';
    
    if (strpos($produto_id, 'custom_') === 0) {
        $post_id = intval(str_replace('custom_', '', $produto_id));
        $produto_nome = get_post_meta($post_id, 'nome_exibicao', true) ?: 'Link Customizado';
        $curso_vinculado = get_post_meta($post_id, 'curso_vinculado', true);
        if (!empty($curso_vinculado) && isset($produtos[$curso_vinculado])) {
            $plan_id = $produtos[$curso_vinculado]['membership_id'];
        }
    } else if ( isset($produtos[$produto_id]) ) {
        $plan_id = $produtos[$produto_id]['membership_id'];
        $produto_nome = $produtos[$produto_id]['nome'];
    }

    $is_new_membership = true;
    if ( $plan_id > 0 ) {
        $user_membership = function_exists('wc_memberships_get_user_membership') ? wc_memberships_get_user_membership( $wp_user_id, $plan_id ) : false;
        
        if ( $user_membership ) {
            $is_new_membership = false;
            if ( $user_membership->get_status() !== 'active' ) {
                $user_membership->update_status( 'active' );
                error_log("Assinatura reativada/renovada para o usuário $wp_user_id no plano $plan_id");
            }
        } else {
            $args = array(
                'plan_id' => $plan_id,
                'user_id' => $wp_user_id
            );
            wc_memberships_create_user_membership( $args );
        }
    }
        
    $user_info = get_userdata($wp_user_id);
    $nome = $user_info ? $user_info->first_name : '';
    $email = $user_info ? $user_info->user_email : '';
    if ($email && $is_new_membership) {
             ead_enviar_email_boas_vindas($email, $nome, $wp_user_id, $produto_id, 'matricular');
        }
        
    // Limpar o lead da lista de abandonados/waitlist
    if ($email) {
        $leads = get_option('reiki_leads_abandonados', array());
        $index = array_search($email, $leads);
        if ($index !== false) {
            unset($leads[$index]);
            update_option('reiki_leads_abandonados', array_values($leads));
        }
    }

        // =========================================
        // REGISTRAR A VENDA NO BANCO E DISPARAR PUSH
        // =========================================
        if (!$registrar_venda) return;
        
        $dedup_key = 'reiki_venda_dedup_' . $wp_user_id . '_' . $produto_id;
        if ( !get_transient($dedup_key) ) {
            set_transient($dedup_key, true, 3600); // Evita duplicidade (API + Webhook) por 1 hora
            
            $post_id = wp_insert_post(array(
                'post_title' => $nome . ' - ' . $produto_nome,
                'post_type' => 'reiki_venda',
                'post_status' => 'publish'
            ));
            
            if ($post_id && $user_info) {
                update_post_meta($post_id, 'cliente_nome', $nome . ' ' . $user_info->last_name);
                update_post_meta($post_id, 'cliente_email', $email);
                update_post_meta($post_id, 'cliente_telefone', get_user_meta($wp_user_id, 'billing_phone', true)); 
                update_post_meta($post_id, 'produto_id', $produto_id);
                update_post_meta($post_id, 'produto_nome', $produto_nome);
                
                if ($valor_nzd !== null) {
                    update_post_meta($post_id, 'valor', 0);
                    update_post_meta($post_id, 'valor_nzd', $valor_nzd);
                    $valor_venda = 0;
                } else {
                    $valor_venda = ($valor_brl_override !== null) ? $valor_brl_override : (isset($produtos[$produto_id]['preco_brl']) ? $produtos[$produto_id]['preco_brl'] : 0);
                    update_post_meta($post_id, 'valor', $valor_venda);
                }
                
                update_post_meta($post_id, 'gateway', $metodo);

                // Id do gateway: deixa o importador deduplicar com precisão (nunca duplica essa venda)
                if ($gw_id !== '') {
                    if ($gw_type === 'asaas') update_post_meta($post_id, '_asaas_payment_id', $gw_id);
                    elseif ($gw_type === 'stripe') update_post_meta($post_id, '_stripe_charge_id', $gw_id);
                }
            }

            if (defined('REIKI_ONESIGNAL_APP_ID') && defined('REIKI_ONESIGNAL_REST_KEY') && REIKI_ONESIGNAL_APP_ID !== '') {
                $valor_display = ($valor_nzd !== null) ? 'NZD ' . number_format($valor_nzd, 2, ',', '.') : 'R$ ' . number_format($valor_venda, 2, ',', '.');
                $push_body = array(
                    'app_id' => REIKI_ONESIGNAL_APP_ID,
                    'included_segments' => array('All'),
                    'headings' => array('en' => '💰 Nova Venda!', 'pt' => '💰 Nova Venda!'),
                    'contents' => array(
                        'en' => $produto_nome . ' - ' . $valor_display . ' (' . $nome . ')',
                        'pt' => $produto_nome . ' - ' . $valor_display . ' (' . $nome . ')'
                    ),
                    'url' => 'https://app.reikitimeacademy.com.br'
                );
                
                wp_remote_post('https://onesignal.com/api/v1/notifications', array(
                    'headers' => array(
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Authorization' => 'Basic ' . REIKI_ONESIGNAL_REST_KEY
                    ),
                    'body' => json_encode($push_body),
                    'timeout' => 15
                ));
            }
        }
}

function processar_acesso_bumps($wp_user_id, $bumps_array) {
    if ( !function_exists('wc_memberships_create_user_membership') ) return;
    
    $dedup_key = 'reiki_bumps_dedup_' . $wp_user_id . '_' . implode('_', $bumps_array);
    if ( get_transient($dedup_key) ) return;
    set_transient($dedup_key, true, 3600);

    foreach ($bumps_array as $bump_id) {
        if ($bump_id === '13031') {
            // Desafio Infinity (Membership)
            if ( !wc_memberships_is_user_active_member($wp_user_id, 13031) ) {
                wc_memberships_create_user_membership( array('plan_id' => 13031, 'user_id' => $wp_user_id) );
            }
        } elseif ($bump_id === '12895') {
            // Deusa AI PRO é um Produto simples! Como nosso fluxo não gera um Pedido WooCommerce nativo,
            // Precisamos criar um pedido automático para que os gatilhos do WooCommerce entreguem o crédito.
            criar_pedido_woo_silencioso($wp_user_id, 12895);
        } elseif ($bump_id === '12224_ext') {
            // Extensão de 6 meses
            if (function_exists('wc_memberships_get_user_membership')) {
                $membership = wc_memberships_get_user_membership($wp_user_id, 12224);
                if ($membership) {
                    $atual  = $membership->get_end_date('timestamp');
                    $base   = ($atual && $atual > time()) ? $atual : time();
                    $novo   = $base + (180 * DAY_IN_SECONDS);
                    $membership->set_end_date(date('Y-m-d H:i:s', $novo));
                    
                    $user_info = get_userdata($wp_user_id);
                    $nome = $user_info ? $user_info->first_name : '';
                    $email = $user_info ? $user_info->user_email : '';
                    if ($email) {
                         ead_enviar_email_boas_vindas($email, $nome, $wp_user_id, 'extensao', 'estender');
                    }
                }
            }
        }
    }
}

function criar_pedido_woo_silencioso($wp_user_id, $product_id) {
    if ( !function_exists('wc_create_order') ) return;
    $product = wc_get_product($product_id);
    if (!$product) return;
    $order = wc_create_order(array('customer_id' => $wp_user_id));
    $order->add_product( $product, 1 );
    $order->calculate_totals();
    $order->update_status('completed', 'Gerado via API Checkout Universal (Bump)');
    
    // Email Deusa
    if ($product_id == 12895) {
        $user_info = get_userdata($wp_user_id);
        $nome = $user_info ? $user_info->first_name : '';
        $email = $user_info ? $user_info->user_email : '';
        if ($email) ead_enviar_email_boas_vindas($email, $nome, $wp_user_id, 'deusa', 'creditos');
    }
}

function buscar_ou_criar_cliente_asaas($nome, $email, $cpf, $telefone, $base_url, $headers) {
    $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
    $search = wp_remote_get( $base_url . '/customers?cpfCnpj=' . $cpf_limpo, array('headers' => $headers) );
    $s_body = json_decode( wp_remote_retrieve_body( $search ), true );
    if ( !empty($s_body['data']) ) return $s_body['data'][0]['id'];

    $create = wp_remote_post( $base_url . '/customers', array(
        'headers' => $headers,
        'body'    => json_encode(array('name' => $nome, 'email' => $email, 'cpfCnpj' => $cpf_limpo, 'mobilePhone' => preg_replace('/[^0-9]/', '', $telefone)))
    ) );
    $c_body = json_decode( wp_remote_retrieve_body( $create ), true );
    if ( isset($c_body['errors']) ) return new WP_Error( 'erro_cliente', $c_body['errors'][0]['description'], array( 'status' => 400 ) );
    return $c_body['id'];
}

function criar_usuario_silencioso($nome, $email) {
    $user = get_user_by( 'email', $email );
    if ( $user ) return $user->ID;

    $senha_aleatoria = wp_generate_password( 12, false );
    $user_id = wp_create_user( $email, $senha_aleatoria, $email );
    if ( is_wp_error($user_id) ) return $user_id;

    $nomes = explode(' ', $nome);
    wp_update_user( array('ID' => $user_id, 'first_name' => $nomes[0], 'last_name' => isset($nomes[1]) ? $nomes[count($nomes)-1] : '') );
    wp_new_user_notification( $user_id, null, 'both' );
    return $user_id;
}

// ============================================
// E-MAILS POR PRODUTO E BUMPS
// ============================================
if (!function_exists('ead_enviar_email_boas_vindas')) {
    function ead_enviar_email_boas_vindas($email, $nome, $user_id, $produto, $acao) {
        $cabecalhos = array('Content-Type: text/html; charset=UTF-8');

        // Gerar link mágico se disponível
        $url_acesso    = 'https://ead.reikitimeacademy.com.br/entrar/';
        $texto_validade = '';
        if (function_exists('cla_generate_magic_link')) {
            $ml = cla_generate_magic_link($user_id);
            $url_acesso     = $ml['url'];
            $texto_validade = "<p style='font-size:11px;color:#999;margin-top:8px;'>Link de uso único · expira em 7 dias.</p>";
        }

        switch ($produto) {
            // ── XÔ REIKI GENÉRICO ──
            case 'xo_reiki':
                $assunto = "Xô, Reiki Genérico! Seu acesso foi liberado 🌸";
                $corpo   = ead_email_xo_reiki($nome, $url_acesso, $texto_validade);
                break;

            // ── INFINITY REIKI ──
            case 'infinity':
                $assunto = "Seu acesso ao Infinity Reiki foi liberado ✦";
                $corpo   = ead_email_infinity($nome, $url_acesso, $texto_validade);
                break;

            // ── EXTENSÃO INFINITY ──
            case 'extensao':
                $assunto = "Seu acesso ao Infinity Reiki foi estendido por mais 6 meses ✦";
                $corpo   = ead_email_extensao($nome, $url_acesso, $texto_validade);
                break;

            // ── DESAFIO INFINITY ──
            case 'desafio':
                $assunto = "O Desafio Infinity começa agora — acesse sua área ✦";
                $corpo   = ead_email_desafio($nome, $url_acesso, $texto_validade);
                break;

            // ── DEUSA AI ──
            case 'deusa':
                $assunto = "Seus créditos Deusa AI PRO foram liberados ✦";
                $corpo   = ead_email_deusa($nome, $url_acesso, $texto_validade);
                break;
                
            // ── EBOOK ──
            case 'ebook':
                $assunto = "Seu E-book Reiki Essencial chegou 📖";
                $corpo   = ead_email_ebook($nome, $url_acesso, $texto_validade);
                break;

            // ── GUARDIÃS (padrão existente) ──
            default:
                $assunto = "Bem-vinda! Seu acesso foi liberado ✨";
                $corpo   = ead_email_guardias($nome, $url_acesso, $texto_validade);
                break;
        }

        wp_mail($email, $assunto, $corpo, $cabecalhos);
    }
}

// ── Templates de E-mail ──
if (!function_exists('ead_email_xo_reiki')) {
function ead_email_xo_reiki($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#1a0a1e;padding:20px;'>
    <div style='max-width:600px;margin:0 auto;background:#200d28;border:1px solid rgba(220,130,200,0.3);border-radius:12px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#2D0A2E,#1A0A2E);padding:50px 30px;text-align:center;border-bottom:1px solid rgba(232,121,249,0.25);'>
        <p style='color:#e879f9;font-size:11px;letter-spacing:3px;text-transform:uppercase;margin:0 0 16px;'>Reiki Time Academy</p>
        <h1 style='color:#f0abfc;font-family:Georgia,serif;font-style:italic;font-size:30px;margin:0 0 10px;font-weight:400;'>Xô, Reiki Genérico!</h1>
        <p style='color:rgba(240,171,252,.55);font-size:14px;margin:0;'>Chegou sua hora, {$nome}</p>
      </div>
      <div style='padding:40px 35px;color:rgba(240,171,252,.8);line-height:1.8;font-size:15px;'>
        <p>Que alegria ter você aqui! Seu acesso está liberado e a jornada para transformar sua prática de Reiki em um negócio próspero começa agora.</p>
        <p style='color:rgba(240,171,252,.5);font-size:13px;'>Aprenda a posicionar seu trabalho, cobrar o que vale e atrair as clientes certas — tudo com autenticidade.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:linear-gradient(135deg,#a855f7,#e879f9);color:#fff;padding:16px 48px;text-decoration:none;border-radius:50px;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;'>Acessar Agora 🌸</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_infinity')) {
function ead_email_infinity($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#0D0D0D;padding:20px;'>
    <div style='max-width:600px;margin:0 auto;background:#111;border:1px solid #C9A84C;border-radius:12px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#0D0D0D,#1C1C1C);padding:50px 30px;text-align:center;border-bottom:1px solid #C9A84C;'>
        <p style='color:#C9A84C;font-size:11px;letter-spacing:3px;text-transform:uppercase;margin:0 0 16px;'>Reiki Time Academy</p>
        <h1 style='color:#E5C97A;font-family:Georgia,serif;font-style:italic;font-size:30px;margin:0 0 10px;font-weight:400;'>Infinity Reiki</h1>
        <p style='color:rgba(255,255,255,.5);font-size:14px;margin:0;'>Seu acesso foi liberado, {$nome}</p>
      </div>
      <div style='padding:40px 35px;color:rgba(255,255,255,.75);line-height:1.8;font-size:15px;'>
        <p>Bem-vinda à sua jornada de Reiki à distância. Todas as aulas, materiais e a comunidade exclusiva já estão disponíveis para você na plataforma.</p>
        <p style='color:rgba(255,255,255,.5);font-size:13px;'>Seu acesso é válido por <strong style='color:#E5C97A;'>6 meses</strong> a partir de hoje.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:linear-gradient(135deg,#C9A84C,#E5C97A);color:#0D0D0D;padding:16px 48px;text-decoration:none;border-radius:50px;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;'>Acessar o Infinity Reiki</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_extensao')) {
function ead_email_extensao($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#0D0D0D;padding:20px;'>
    <div style='max-width:600px;margin:0 auto;background:#111;border:1px solid #C9A84C;border-radius:12px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#0D0D0D,#1C1C1C);padding:50px 30px;text-align:center;border-bottom:1px solid #C9A84C;'>
        <h1 style='color:#E5C97A;font-family:Georgia,serif;font-style:italic;font-size:30px;margin:0 0 10px;font-weight:400;'>+6 Meses de Acesso</h1>
        <p style='color:rgba(255,255,255,.5);font-size:14px;margin:0;'>Sua jornada continua, {$nome}</p>
      </div>
      <div style='padding:40px 35px;color:rgba(255,255,255,.75);line-height:1.8;font-size:15px;'>
        <p>Seu acesso ao Infinity Reiki foi estendido por mais <strong style='color:#E5C97A;'>6 meses</strong>. Continue sua prática sem interrupção — todas as aulas e materiais seguem disponíveis.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:linear-gradient(135deg,#C9A84C,#E5C97A);color:#0D0D0D;padding:16px 48px;text-decoration:none;border-radius:50px;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;'>Continuar Minha Jornada</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_desafio')) {
function ead_email_desafio($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#0D0D0D;padding:20px;'>
    <div style='max-width:600px;margin:0 auto;background:#111;border:1px solid #C9A84C;border-radius:12px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#0D0D0D,#1C1C1C);padding:50px 30px;text-align:center;border-bottom:1px solid #C9A84C;'>
        <h1 style='color:#E5C97A;font-family:Georgia,serif;font-style:italic;font-size:30px;margin:0 0 10px;font-weight:400;'>Desafio Infinity</h1>
        <p style='color:rgba(255,255,255,.5);font-size:14px;margin:0;'>O desafio começa agora, {$nome}</p>
      </div>
      <div style='padding:40px 35px;color:rgba(255,255,255,.75);line-height:1.8;font-size:15px;'>
        <p>Seu acesso ao <strong style='color:#E5C97A;'>Desafio Infinity</strong> foi liberado.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:linear-gradient(135deg,#C9A84C,#E5C97A);color:#0D0D0D;padding:16px 48px;text-decoration:none;border-radius:50px;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;'>Começar o Desafio</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_deusa')) {
function ead_email_deusa($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#0D0D0D;padding:20px;'>
    <div style='max-width:600px;margin:0 auto;background:#111;border:1px solid #C9A84C;border-radius:12px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#080810,#12121E);padding:50px 30px;text-align:center;border-bottom:1px solid #C9A84C;'>
        <h1 style='color:#E5C97A;font-family:Georgia,serif;font-style:italic;font-size:30px;margin:0 0 10px;font-weight:400;'>Deusa AI PRO</h1>
        <p style='color:rgba(255,255,255,.5);font-size:14px;margin:0;'>Créditos liberados, {$nome}</p>
      </div>
      <div style='padding:40px 35px;color:rgba(255,255,255,.75);line-height:1.8;font-size:15px;'>
        <p>Seus <strong style='color:#E5C97A;'>créditos PRO</strong> já estão na sua conta. Use para gerar Análises.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:linear-gradient(135deg,#C9A84C,#E5C97A);color:#0D0D0D;padding:16px 48px;text-decoration:none;border-radius:50px;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;'>Acessar a Deusa AI</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_guardias')) {
function ead_email_guardias($nome, $url, $validade) {
    $rosa = '#8b2942'; $ouro = '#c9a45a';
    return "
    <html><body style='font-family:sans-serif;background:#f4f1ef;padding:20px;color:#333;'>
    <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;'>
      <div style='background:$rosa;padding:40px 20px;text-align:center;border-radius:12px 12px 0 0;'>
        <h1 style='color:#fff;font-family:serif;font-style:italic;margin:0;font-size:28px;'>Bem-vinda, {$nome}</h1>
      </div>
      <div style='padding:40px;line-height:1.7;'>
        <p>Honramos sua chegada. Seu acesso ao portal já está disponível.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:$rosa;color:#fff;padding:18px 45px;text-decoration:none;border-radius:50px;font-weight:bold;border:1px solid $ouro;'>✨ ENTRAR NO PORTAL AGORA</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

if (!function_exists('ead_email_ebook')) {
function ead_email_ebook($nome, $url, $validade) {
    return "
    <html><body style='font-family:sans-serif;background:#f4f1ef;padding:20px;color:#333;'>
    <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;'>
      <div style='background:#111;padding:40px 20px;text-align:center;border-radius:12px 12px 0 0;'>
        <h1 style='color:#E5C97A;font-family:serif;font-style:italic;margin:0;font-size:28px;'>E-book Reiki Essencial</h1>
      </div>
      <div style='padding:40px;line-height:1.7;'>
        <p>Olá {$nome}, seu E-book Reiki Essencial está pronto para leitura.</p>
        <p>Acesse o portal e baixe seu material no primeiro módulo.</p>
        <div style='text-align:center;margin:40px 0;'>
          <a href='{$url}' style='display:inline-block;background:#111;color:#E5C97A;padding:18px 45px;text-decoration:none;border-radius:50px;font-weight:bold;'>Acessar Meu Material</a>
          {$validade}
        </div>
      </div>
    </div>
    </body></html>";
}
}

// =========================================================================
// CRON JOB: Limpeza de Cartões Presos (Transações Híbridas)
// =========================================================================
add_action('reiki_verificar_cartao_preso', 'reiki_verificar_cartao_preso_callback');
function reiki_verificar_cartao_preso_callback($payment_id) {
    // Checa o status do pagamento no Asaas
    $response = reiki_asaas_request('GET', '/payments/' . $payment_id);
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['status']) && $body['status'] === 'AUTHORIZED') {
            // Se ainda está AUTHORIZED após 30 mins, o Pix/Boleto nunca foi pago ou o webhook falhou gravemente.
            // Cancela para liberar o limite do cartão do cliente.
            reiki_asaas_cancelar($payment_id);
            error_log('CRON: Cartão preso ' . $payment_id . ' cancelado por expiração de segurança (30m).');
        }
    }
}

// =========================================================================
// CAPTURA DE LEADS E DASHBOARD PWA
// =========================================================================
function processar_lead_checkout( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://checkout.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    // Rate limit anti-flood (20/15min por IP). Resposta silenciosa p/ não sinalizar o limite a bots
    // nem quebrar o fire-and-forget do frontend.
    if (!reiki_admin_rate('lead', 20, 900)) {
        return rest_ensure_response( array('sucesso' => true) );
    }

    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_body_params();

    $nome = sanitize_text_field( $params['nome'] ?? '' );
    $email = sanitize_email( $params['email'] ?? '' );
    $telefone = sanitize_text_field( $params['telefone'] ?? '' );
    $produto_id = sanitize_text_field( $params['produto'] ?? 'desconhecido' );

    if ( !empty($nome) && !empty($email) ) {
        $leads = get_option('reiki_leads_abandonados', array());
        if (!is_array($leads)) $leads = array();
        
        $key = $email . '_' . $produto_id;
        $leads[$key] = array(
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'produto' => $produto_id,
            'hora' => current_time('mysql')
        );
        
        // Limita o tamanho do array para 500 para evitar OOM no WordPress
        if (count($leads) > 500) {
            $leads = array_slice($leads, -500, null, true);
        }
        
        if (get_option('reiki_leads_abandonados', false) === false) {
            add_option('reiki_leads_abandonados', $leads, '', 'no'); // autoload off
        } else {
            update_option('reiki_leads_abandonados', $leads);
        }
    }
    return rest_ensure_response( array('sucesso' => true) );
}

function processar_lista_espera( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://vagas-esgotadas.reikitimeacademy.com.br',
        'https://reiki-checkout.pages.dev' // Em caso de testes locais
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    // Rate limit anti-flood (silencioso)
    if (!reiki_admin_rate('waitlist', 20, 900)) {
        return rest_ensure_response( array('sucesso' => true) );
    }

    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_body_params();

    $email = sanitize_email( $params['email'] ?? '' );
    $phone = sanitize_text_field( $params['phone'] ?? '' );
    $origem = sanitize_text_field( $params['origem'] ?? 'geral' );

    if ( !empty($email) || !empty($phone) ) {
        $waitlist = get_option('reiki_lista_espera', array());
        if (!is_array($waitlist)) $waitlist = array();
        
        $key = (!empty($email) ? $email : $phone) . '_' . $origem;
        $waitlist[$key] = array(
            'email' => $email,
            'phone' => $phone,
            'origem' => $origem,
            'hora' => current_time('mysql')
        );
        if (count($waitlist) > 1000) {
            $waitlist = array_slice($waitlist, -1000, null, true);
        }
        if (get_option('reiki_lista_espera', false) === false) {
            add_option('reiki_lista_espera', $waitlist, '', 'no'); // autoload off
        } else {
            update_option('reiki_lista_espera', $waitlist);
        }
    }
    return rest_ensure_response( array('sucesso' => true) );
}

function reiki_dashboard_api( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    if (!reiki_is_admin($request)) {
        return new WP_Error('nao_autorizado', 'Acesso negado', array('status' => 401));
    }

    $args = array(
        'post_type' => 'reiki_venda',
        'posts_per_page' => -1,        // necessário p/ os totais "Tudo" do dashboard
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,       // pula SQL_CALC_FOUND_ROWS (não há paginação aqui)
        'update_post_term_cache' => false // vendas não usam taxonomias
    );
    $query = new WP_Query($args);
    $vendas = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $vendas[] = array(
                'id' => $id,
                'date' => get_the_date('c'),
                'customer' => get_post_meta($id, 'cliente_nome', true),
                'email' => get_post_meta($id, 'cliente_email', true),
                'whatsapp' => get_post_meta($id, 'cliente_telefone', true),
                'product' => get_post_meta($id, 'produto_id', true),
                'productName' => get_post_meta($id, 'produto_nome', true),
                'value' => floatval(get_post_meta($id, 'valor', true)),
                'value_nzd' => floatval(get_post_meta($id, 'valor_nzd', true)),
                'gateway' => get_post_meta($id, 'gateway', true)
            );
        }
    }
    wp_reset_postdata();

    $leads_raw = get_option('reiki_leads_abandonados', array());
    $leads = array();
    if (is_array($leads_raw)) {
        foreach($leads_raw as $lead) {
            $leads[] = array(
                'id' => md5($lead['email'] . $lead['produto']),
                'name' => $lead['nome'],
                'email' => $lead['email'],
                'phone' => $lead['telefone'],
                'product' => $lead['produto'],
                'date' => $lead['hora']
            );
        }
        usort($leads, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
    }

    // --- BALANCES (cache de 60s: evita martelar Asaas/Stripe a cada refresh/visibilitychange) ---
    $balances_cache = get_transient('reiki_balances_cache');
    if (is_array($balances_cache) && isset($balances_cache['asaas'], $balances_cache['stripe'])) {
        $asaas_balance = $balances_cache['asaas'];
        $stripe_balance = $balances_cache['stripe'];
    } else {
        $asaas_balance = 0;
        $asaas_response = reiki_asaas_request('GET', '/finance/balance');
        if (!is_wp_error($asaas_response)) {
            $body = json_decode(wp_remote_retrieve_body($asaas_response), true);
            if (isset($body['balance'])) {
                $asaas_balance = floatval($body['balance']);
            }
        }

        $stripe_balance = array('available' => array(), 'pending' => array(), 'instant_available' => array());
        $stripe_args = array('headers' => array('Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY));
        $stripe_response = wp_remote_get('https://api.stripe.com/v1/balance', $stripe_args);
        if (!is_wp_error($stripe_response)) {
            $body = json_decode(wp_remote_retrieve_body($stripe_response), true);
            if (isset($body['available']) && is_array($body['available'])) {
                $stripe_balance['available'] = $body['available'];
            }
            if (isset($body['pending']) && is_array($body['pending'])) {
                $stripe_balance['pending'] = $body['pending'];
            }
            if (isset($body['instant_available']) && is_array($body['instant_available'])) {
                $stripe_balance['instant_available'] = $body['instant_available'];
            }
        }

        set_transient('reiki_balances_cache', array('asaas' => $asaas_balance, 'stripe' => $stripe_balance), 60);
    }

    $waitlist_raw = get_option('reiki_lista_espera', array());
    $waitlist = array();
    if (is_array($waitlist_raw)) {
        foreach($waitlist_raw as $w) {
            $e = isset($w['email']) ? $w['email'] : '';
            $p = isset($w['phone']) ? $w['phone'] : '';
            $waitlist[] = array(
                'id' => md5($e . $p . $w['origem']),
                'email' => $e,
                'phone' => $p,
                'origem' => $w['origem'],
                'date' => $w['hora']
            );
        }
        usort($waitlist, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
    }

    return rest_ensure_response(array(
        'vendas' => $vendas, 
        'leads' => array_values($leads),
        'waitlist' => array_values($waitlist),
        'balances' => array(
            'asaas' => $asaas_balance,
            'stripe' => $stripe_balance
        )
    ));
}

function reiki_dashboard_delete_lead_api( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    if (!reiki_is_admin($request)) {
        return new WP_Error('nao_autorizado', 'Acesso negado', array('status' => 401));
    }

    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_body_params();
    $id_to_delete = sanitize_text_field( $params['id'] ?? '' );

    if (empty($id_to_delete)) {
        return new WP_Error('missing_id', 'ID do lead não fornecido', array('status' => 400));
    }

    $leads_raw = get_option('reiki_leads_abandonados', array());
    $updated_leads = array();
    $deleted = false;

    if (is_array($leads_raw)) {
        foreach($leads_raw as $key => $lead) {
            $lead_id = md5($lead['email'] . $lead['produto']);
            if ($lead_id === $id_to_delete) {
                $deleted = true;
                continue; // Pula este (deleta)
            }
            $updated_leads[$key] = $lead;
        }
        if ($deleted) {
            update_option('reiki_leads_abandonados', $updated_leads);
        }
    }

    if (!$deleted) {
        $waitlist_raw = get_option('reiki_lista_espera', array());
        $updated_waitlist = array();
        if (is_array($waitlist_raw)) {
            foreach($waitlist_raw as $key => $w) {
                $e = isset($w['email']) ? $w['email'] : '';
                $p = isset($w['phone']) ? $w['phone'] : '';
                $w_id = md5($e . $p . $w['origem']);
                if ($w_id === $id_to_delete) {
                    $deleted = true;
                    continue;
                }
                $updated_waitlist[$key] = $w;
            }
            if ($deleted) {
                update_option('reiki_lista_espera', $updated_waitlist);
            }
        }
    }

    return rest_ensure_response(array('sucesso' => true, 'deleted' => $deleted));
}

// =========================================================================
// SCRIPT DE IMPORTAÇÃO (TEMPORÁRIO)
// =========================================================================
function reiki_import_junho_history( WP_REST_Request $request ) {
    reiki_admin_cors();
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }
    if (!reiki_is_admin($request)) {
        return new WP_Error('nao_autorizado', 'Acesso negado', array('status' => 401));
    }

    // Busca 100 últimos pagamentos do Asaas desde 01 de Junho
    $response = reiki_asaas_request('GET', '/payments?dateCreated[ge]=2026-06-01&limit=100');
    if (is_wp_error($response)) {
        return rest_ensure_response(array('sucesso' => false, 'erro' => 'Falha ao conectar no Asaas'));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['data'])) {
        return rest_ensure_response(array('sucesso' => false, 'erro' => 'Nenhum dado retornado', 'raw' => $body));
    }

    $importadas = 0;
    $ignoradas_parcelas = 0;
    $ignoradas_duplicadas = 0;
    $log = array();
    $customers_cache = array();

    foreach ($body['data'] as $payment) {
        $status = $payment['status'];
        
        // Só importa pagamentos que foram confirmados/recebidos
        if (!in_array($status, array('RECEIVED', 'CONFIRMED', 'DUNNING_REQUESTED'))) {
            continue;
        }

        $payment_id = $payment['id'];

        // Checa se já importamos esse pagamento exato
        $args_check = array(
            'post_type' => 'reiki_venda',
            'meta_key' => '_asaas_payment_id',
            'meta_value' => $payment_id,
            'posts_per_page' => 1
        );
        $check_query = new WP_Query($args_check);
        if ($check_query->have_posts()) {
            $ignoradas_duplicadas++;
            continue;
        }

        $billingType = isset($payment['billingType']) ? $payment['billingType'] : '';
        $gateway = ($billingType === 'CREDIT_CARD') ? 'Cartão de Crédito (Asaas)' : (($billingType === 'PIX') ? 'PIX (Asaas)' : 'Boleto (Asaas)');

        // Lógica BLINDADA para Parcelamento
        $is_installment = !empty($payment['installment']);
        $parcela_atual = 1;
        $desc = isset($payment['description']) ? $payment['description'] : 'Venda Asaas';
        $produto_nome = $desc;

        if ($is_installment) {
            // Descobre qual é a parcela atual
            if (isset($payment['installmentNumber'])) {
                $parcela_atual = intval($payment['installmentNumber']);
            } elseif (preg_match('/Parcela (\d+)/i', $desc, $m)) {
                $parcela_atual = intval($m[1]);
            }
            
            // Pula qualquer parcela que não seja a número 1, MAS APENAS se for Cartão de Crédito
            if ($parcela_atual > 1 && $billingType === 'CREDIT_CARD') {
                $ignoradas_parcelas++;
                continue;
            }
        }

        $valor_venda = floatval($payment['value']);
        
        // Limpa o nome do produto e calcula o valor total se for parcelado
        if (preg_match('/Parcela \d+ de (\d+)/i', $desc, $matches)) {
            $total_parcelas = intval($matches[1]);
            
            // Multiplica o valor APENAS se for Cartão de Crédito (limite garantido)
            if ($billingType === 'CREDIT_CARD') {
                $valor_venda = $valor_venda * $total_parcelas; // Faturamento cheio!
            }
            
            // Tenta limpar o nome do produto
            $produto_nome = trim(preg_replace('/Parcela \d+ de \d+\.?\s*/i', '', $desc));
            $produto_nome = str_ireplace('Compra: ', '', $produto_nome);
            $produto_nome = str_ireplace('Pedido ', 'Pedido ', $produto_nome);
            
            // Se for Pix/Boleto, adiciona " (Parcela X)" no nome para ficar bonito no PWA
            if ($billingType !== 'CREDIT_CARD') {
                $produto_nome .= ' (Parcela ' . $parcela_atual . ')';
            }
            
        } elseif (preg_match('/Compra:\s*(.+)/i', $desc, $matches)) {
            $produto_nome = trim($matches[1]);
        }

        // Pega os dados do cliente (com cache)
        $customer_id = $payment['customer'];
        if (!isset($customers_cache[$customer_id])) {
            $cust_req = reiki_asaas_request('GET', '/customers/' . $customer_id);
            if (!is_wp_error($cust_req)) {
                $customers_cache[$customer_id] = json_decode(wp_remote_retrieve_body($cust_req), true);
            }
        }
        
        $cust_data = isset($customers_cache[$customer_id]) ? $customers_cache[$customer_id] : array();
        $cliente_nome = isset($cust_data['name']) ? $cust_data['name'] : 'Cliente Importado';
        $cliente_email = isset($cust_data['email']) ? $cust_data['email'] : '';
        $cliente_telefone = isset($cust_data['phone']) ? $cust_data['phone'] : (isset($cust_data['mobilePhone']) ? $cust_data['mobilePhone'] : '');

        // Usa a data de confirmação se existir, senão a de criação
        $data_venda = isset($payment['confirmedDate']) ? $payment['confirmedDate'] : $payment['dateCreated'];
        $post_date = date('Y-m-d H:i:s', strtotime($data_venda));

        // Insere a venda no banco
        $post_id = wp_insert_post(array(
            'post_title' => $cliente_nome . ' - ' . $produto_nome,
            'post_type' => 'reiki_venda',
            'post_status' => 'publish',
            'post_date' => $post_date
        ));

        if ($post_id) {
            update_post_meta($post_id, 'cliente_nome', $cliente_nome);
            update_post_meta($post_id, 'cliente_email', $cliente_email);
            update_post_meta($post_id, 'cliente_telefone', $cliente_telefone);
            update_post_meta($post_id, 'produto_id', sanitize_title($produto_nome));
            update_post_meta($post_id, 'produto_nome', $produto_nome);
            update_post_meta($post_id, 'valor', $valor_venda);
            update_post_meta($post_id, 'gateway', $gateway);
            update_post_meta($post_id, '_asaas_payment_id', $payment_id); // Trava anti-duplicação

            $importadas++;
            $log[] = "Importado Asaas: $cliente_nome - $produto_nome (R$ $valor_venda) - Data: $post_date";
        }
    }

    // ==========================================
    // IMPORTAR ASSINATURAS/VENDAS ANTIGAS DA STRIPE (DIRETO DA API)
    // ==========================================
    $stripe_args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY,
        )
    );
    
    $june_1 = strtotime('2026-06-01 00:00:00');
    $stripe_url = 'https://api.stripe.com/v1/charges?created[gte]=' . $june_1 . '&limit=100&expand[]=data.balance_transaction';
    
    $stripe_res = wp_remote_get($stripe_url, $stripe_args);
    if (!is_wp_error($stripe_res)) {
        $s_body = json_decode(wp_remote_retrieve_body($stripe_res), true);
        if (isset($s_body['data'])) {
            foreach ($s_body['data'] as $charge) {
                if ($charge['paid'] !== true || $charge['refunded'] === true) continue;
                
                $charge_id = $charge['id'];
                
                $args_check_stripe = array(
                    'post_type' => 'reiki_venda',
                    'meta_key' => '_stripe_charge_id',
                    'meta_value' => $charge_id,
                    'posts_per_page' => 1
                );
                $check_query_stripe = new WP_Query($args_check_stripe);
                if ($check_query_stripe->have_posts()) {
                    $ignoradas_duplicadas++;
                    continue;
                }

                $cliente_nome = isset($charge['billing_details']['name']) && !empty($charge['billing_details']['name']) ? $charge['billing_details']['name'] : 'Cliente Stripe';
                $cliente_email = isset($charge['billing_details']['email']) ? $charge['billing_details']['email'] : '';
                $cliente_telefone = isset($charge['billing_details']['phone']) ? $charge['billing_details']['phone'] : '';
                
                // Pega o valor líquido (net) do balance_transaction
                $valor_nzd = 0;
                if (isset($charge['balance_transaction']) && is_array($charge['balance_transaction'])) {
                    if (isset($charge['balance_transaction']['net'])) {
                        $valor_nzd = $charge['balance_transaction']['net'] / 100;
                    }
                } else {
                    $valor_nzd = $charge['amount'] / 100;
                }
                
                $gateway = 'Cartão de Crédito (Stripe)';
                $produto_nome = isset($charge['description']) && !empty($charge['description']) ? $charge['description'] : 'Assinatura Stripe';
                $produto_nome = str_ireplace('Subscription creation', 'Assinatura', $produto_nome);

                $post_date = date('Y-m-d H:i:s', $charge['created']);
                
                $post_id = wp_insert_post(array(
                    'post_title' => $cliente_nome . ' - ' . $produto_nome,
                    'post_type' => 'reiki_venda',
                    'post_status' => 'publish',
                    'post_date' => $post_date
                ));

                if ($post_id) {
                    update_post_meta($post_id, 'cliente_nome', $cliente_nome);
                    update_post_meta($post_id, 'cliente_email', $cliente_email);
                    update_post_meta($post_id, 'cliente_telefone', $cliente_telefone);
                    update_post_meta($post_id, 'produto_id', sanitize_title($produto_nome));
                    update_post_meta($post_id, 'produto_nome', $produto_nome);
                    
                    update_post_meta($post_id, 'valor', 0); // Zera no BR
                    update_post_meta($post_id, 'valor_nzd', $valor_nzd);
                    
                    update_post_meta($post_id, 'gateway', $gateway);
                    update_post_meta($post_id, '_stripe_charge_id', $charge_id);

                    $importadas++;
                    $log[] = "Importado Stripe: $cliente_nome - $produto_nome ($ $valor_nzd NZD) - Data: $post_date";
                }
            }
        }
    }

    return rest_ensure_response(array(
        'sucesso' => true,
        'resumo' => array(
            'importadas_com_sucesso' => $importadas,
            'ignoradas_parcelas_futuras' => $ignoradas_parcelas,
            'ignoradas_ja_existentes' => $ignoradas_duplicadas
        ),
        'log_detalhado' => $log
    ));
}

// =========================================================================
// 4. STRIPE INTENT CREATION (NOVO FLUXO)
// =========================================================================
function processar_stripe_intent_criacao( WP_REST_Request $request ) {
    $allowed_origins = array('https://checkout.reikitimeacademy.com.br', 'https://reiki-checkout.pages.dev');
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) header("Access-Control-Allow-Origin: " . $origin);
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) return rest_ensure_response( array('status' => 'ok') );

    $params = $request->get_json_params();
    $produto_id = sanitize_text_field( $params['produto'] );
    $currency = strtolower(sanitize_text_field( $params['currency'] ?? 'usd' ));
    $bumps_selecionados = isset($params['bumps']) && is_array($params['bumps']) ? array_map('sanitize_text_field', $params['bumps']) : array();
    $cupom_aplicado = sanitize_text_field( $params['cupom'] ?? '' );

    $produtos = get_reiki_products();
    $produto_info = null;
    $is_subscription = false;
    
    if (strpos($produto_id, 'custom_') === 0) {
        $post_id = intval(str_replace('custom_', '', $produto_id));
        $post = get_post($post_id);
        if ($post && $post->post_type === 'reiki_custom_link') {
            $produto_info = array(
                'preco_usd' => floatval(get_post_meta($post_id, 'preco_usd', true)),
                'preco_eur' => floatval(get_post_meta($post_id, 'preco_eur', true))
            );
            $is_subscription = get_post_meta($post_id, 'is_subscription', true) === '1';
        }
    } else {
        $produto_info = isset($produtos[$produto_id]) ? $produtos[$produto_id] : null;
    }

    if ( empty( $produto_info ) ) return new WP_Error( 'erro_produto', 'Produto não encontrado', array( 'status' => 400 ) );

    $valor_total = ($currency === 'eur') ? $produto_info['preco_eur'] : $produto_info['preco_usd'];

    $bumps_config = get_reiki_bumps();

    foreach ($bumps_selecionados as $bump_id) {
        if (isset($bumps_config[$bump_id])) {
            $valor_total += ($currency === 'eur') ? $bumps_config[$bump_id]['eur'] : $bumps_config[$bump_id]['usd'];
        }
    }

    if ( !empty($cupom_aplicado) && class_exists('WC_Coupon') ) {
        $coupon = new WC_Coupon( $cupom_aplicado );
        if ( $coupon->get_id() && $coupon->is_valid() && $coupon->get_discount_type() === 'percent' ) {
            $valor_total = $valor_total - ($valor_total * (floatval($coupon->get_amount()) / 100));
        }
    }
    if ($valor_total < 0) $valor_total = 0;
    
    if ($is_subscription && $post_id) {
        $parcelas_ass = intval(get_post_meta($post_id, 'parcelas_subscription', true) ?: 1);
        if ($parcelas_ass < 2) $parcelas_ass = 2;
        $valor_total = round($valor_total / $parcelas_ass, 2);
    }

    $amount_cents = intval( $valor_total * 100 );

    $stripe_headers = array(
        'Authorization' => 'Bearer ' . REIKI_STRIPE_SECRET_KEY,
        'Content-Type'  => 'application/x-www-form-urlencoded'
    );

    if ($is_subscription) {
        // Para assinaturas precisamos de um SetupIntent
        $stripe_body = http_build_query(array(
            'usage' => 'off_session',
            'payment_method_types[0]' => 'card', // SÓ cartão no nosso checkout (bloqueia MB WAY, SEPA, Afterpay, etc.)
            'metadata[produto_id]' => $produto_id,
            'metadata[bumps]' => implode(',', $bumps_selecionados),
            'metadata[cupom_id]' => ($cupom_aplicado && class_exists('WC_Coupon')) ? (new WC_Coupon($cupom_aplicado))->get_id() : ''
        ));
        $response = wp_remote_post( 'https://api.stripe.com/v1/setup_intents', array(
            'headers' => $stripe_headers,
            'body'    => $stripe_body,
            'timeout' => 30
        ) );
    } else {
        // Fluxo normal: PaymentIntent
        $stripe_body = http_build_query(array(
            'amount' => $amount_cents,
            'currency' => $currency,
            'payment_method_types[0]' => 'card', // SÓ cartão no nosso checkout (bloqueia MB WAY, SEPA, Afterpay, etc.)
            'metadata[produto_id]' => $produto_id,
            'metadata[amount_esperado]' => $amount_cents,
            'metadata[bumps]' => implode(',', $bumps_selecionados),
            'metadata[cupom_id]' => ($cupom_aplicado && class_exists('WC_Coupon')) ? (new WC_Coupon($cupom_aplicado))->get_id() : ''
        ));
        $response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', array(
            'headers' => $stripe_headers,
            'body'    => $stripe_body,
            'timeout' => 30
        ) );
    }

    if ( is_wp_error( $response ) ) return new WP_Error( 'erro_api', 'Falha ao conectar ao Stripe.', array( 'status' => 500 ) );

    $body_resp = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset($body_resp['error']) ) return new WP_Error( 'recusado', $body_resp['error']['message'], array( 'status' => 400 ) );

    return rest_ensure_response( array(
        'client_secret' => $body_resp['client_secret'],
        'payment_intent_id' => $body_resp['id']
    ) );
}

// =========================================================================
// 5. WEBHOOK VENDA EXTERNA (ID FEMININO)
// =========================================================================
function registrar_venda_externa_api( WP_REST_Request $request ) {
    $allowed_origins = array(
        'https://idfeminino.com.br'
    );
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    }
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, x-reiki-api-key");

    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        return rest_ensure_response( array('status' => 'ok') );
    }

    // Rate limit anti-abuso (60/h por IP)
    if (!reiki_admin_rate('venda_externa', 60, 3600)) {
        return new WP_Error( 'rate_limit', 'Muitas tentativas.', array( 'status' => 429 ) );
    }

    // SEGURANÇA: token vem de REIKI_EXTERNAL_API_KEY (wp-config); comparação constant-time
    $token_enviado = (string) $request->get_header('x-reiki-api-key');
    if ( !hash_equals(REIKI_EXTERNAL_API_KEY, $token_enviado) ) {
        return new WP_Error( 'nao_autorizado', 'Token de API inválido', array( 'status' => 401 ) );
    }

    $params = $request->get_json_params();
    $nome = sanitize_text_field( $params['nome'] );
    $email = sanitize_email( $params['email'] );
    $telefone = sanitize_text_field( $params['telefone'] ?? '' );
    $produto_nome = sanitize_text_field( $params['produto_nome'] );
    $valor = floatval( $params['valor'] );
    $gateway = sanitize_text_field( $params['gateway'] ?? 'Externo' );
    $order_id_ext = sanitize_text_field( $params['order_id'] ?? time() );
    $data_venda = sanitize_text_field( $params['data_venda'] ?? '' );
    
    // Evitar duplicidade pelo ID do pedido externo
    $dedup_key = 'reiki_venda_ext_' . $order_id_ext;
    if ( get_transient($dedup_key) ) {
        return rest_ensure_response( array('recebido' => true, 'msg' => 'Venda já registrada.') );
    }
    set_transient($dedup_key, true, 86400); // 24h

    $post_data = array(
        'post_title' => $nome . ' - ' . $produto_nome,
        'post_type' => 'reiki_venda',
        'post_status' => 'publish'
    );
    
    if (!empty($data_venda)) {
        $post_data['post_date'] = date('Y-m-d H:i:s', strtotime($data_venda));
    }

    $post_id = wp_insert_post($post_data);
    
    if ($post_id) {
        update_post_meta($post_id, 'cliente_nome', $nome);
        update_post_meta($post_id, 'cliente_email', $email);
        update_post_meta($post_id, 'cliente_telefone', $telefone);
        update_post_meta($post_id, 'produto_id', 'ext_' . sanitize_title($produto_nome));
        update_post_meta($post_id, 'produto_nome', $produto_nome);
        update_post_meta($post_id, 'valor', $valor);
        update_post_meta($post_id, 'gateway', $gateway);
    }

    // Disparar Push OneSignal
    if (defined('REIKI_ONESIGNAL_APP_ID') && defined('REIKI_ONESIGNAL_REST_KEY') && REIKI_ONESIGNAL_APP_ID !== '') {
        $push_body = array(
            'app_id' => REIKI_ONESIGNAL_APP_ID,
            'included_segments' => array('All'),
            'headings' => array('en' => '💰 Nova Venda!', 'pt' => '💰 Nova Venda!'),
            'contents' => array(
                'en' => $produto_nome . ' - R$ ' . number_format($valor, 2, ',', '.') . ' (' . $nome . ')',
                'pt' => $produto_nome . ' - R$ ' . number_format($valor, 2, ',', '.') . ' (' . $nome . ')'
            ),
            'url' => 'https://app.reikitimeacademy.com.br'
        );
        
        wp_remote_post('https://onesignal.com/api/v1/notifications', array(
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . REIKI_ONESIGNAL_REST_KEY
            ),
            'body' => json_encode($push_body),
            'timeout' => 15
        ));
    }

    return rest_ensure_response( array('sucesso' => true, 'post_id' => $post_id) );
}

// =========================================================================
// LOG DE TRANSAÇÃO (T3.3) — rastreia o ciclo de vida do pagamento
// =========================================================================
if (!defined('REIKI_TX_LOG_DB_VERSION')) define('REIKI_TX_LOG_DB_VERSION', '1');

// Cria/atualiza a tabela própria do log (uma vez; idempotente)
add_action('init', function () {
    if (get_option('reiki_tx_log_db') === REIKI_TX_LOG_DB_VERSION) return;
    global $wpdb;
    $table = $wpdb->prefix . 'reiki_tx_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        event VARCHAR(40) NOT NULL,
        gateway VARCHAR(30) DEFAULT '',
        payment_id VARCHAR(80) DEFAULT '',
        wp_user_id BIGINT UNSIGNED DEFAULT 0,
        email VARCHAR(190) DEFAULT '',
        produto_id VARCHAR(60) DEFAULT '',
        amount DECIMAL(12,2) DEFAULT 0,
        status VARCHAR(60) DEFAULT '',
        detail TEXT,
        PRIMARY KEY (id),
        KEY payment_id (payment_id),
        KEY email (email),
        KEY created_at (created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('reiki_tx_log_db', REIKI_TX_LOG_DB_VERSION);
});

// Insere um evento no log. Falha silenciosa (nunca quebra um pagamento por causa de log).
function reiki_log_tx($event, $data = array()) {
    global $wpdb;
    try {
        $wpdb->insert($wpdb->prefix . 'reiki_tx_log', array(
            'created_at' => current_time('mysql'),
            'event'      => substr((string)$event, 0, 40),
            'gateway'    => substr((string)($data['gateway'] ?? ''), 0, 30),
            'payment_id' => substr((string)($data['payment_id'] ?? ''), 0, 80),
            'wp_user_id' => intval($data['wp_user_id'] ?? 0),
            'email'      => substr((string)($data['email'] ?? ''), 0, 190),
            'produto_id' => substr((string)($data['produto_id'] ?? ''), 0, 60),
            'amount'     => floatval($data['amount'] ?? 0),
            'status'     => substr((string)($data['status'] ?? ''), 0, 60),
            'detail'     => substr((string)($data['detail'] ?? ''), 0, 2000),
        ));
    } catch (\Throwable $e) {
        error_log('reiki_log_tx falhou: ' . $e->getMessage());
    }
}

// Painel no wp-admin: Reiki TX (somente admin)
add_action('admin_menu', function () {
    add_menu_page('Reiki Transações', 'Reiki TX', 'manage_options', 'reiki-tx-log', 'reiki_tx_log_admin_page', 'dashicons-list-view', 56);
});

function reiki_tx_log_admin_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'reiki_tx_log';
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE payment_id LIKE %s OR email LIKE %s OR produto_id LIKE %s ORDER BY id DESC LIMIT 300",
            $like, $like, $like
        ));
    } else {
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 300");
    }

    echo '<div class="wrap"><h1>Reiki — Log de Transações</h1>';
    echo '<p>Rastreie uma venda por <strong>payment_id</strong>, e-mail ou produto. Mostra os 300 mais recentes.</p>';
    echo '<form method="get"><input type="hidden" name="page" value="reiki-tx-log">';
    echo '<input type="search" name="q" value="' . esc_attr($q) . '" placeholder="payment_id, e-mail ou produto" style="width:320px;padding:6px;">';
    echo ' <button class="button button-primary">Buscar</button>';
    if ($q !== '') echo ' <a class="button" href="?page=reiki-tx-log">Limpar</a>';
    echo '</form><br>';

    if (empty($rows)) {
        echo '<p><em>Nenhum registro' . ($q !== '' ? ' para "' . esc_html($q) . '"' : '') . '.</em></p></div>';
        return;
    }

    $cor = array('acesso_liberado' => '#1a7f37', 'erro' => '#b32d2e', 'webhook' => '#1d4ed8');
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Quando</th><th>Evento</th><th>Gateway</th><th>Payment ID</th><th>Usuário</th><th>E-mail</th><th>Produto</th><th>Valor</th><th>Status</th><th>Detalhe</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $c = isset($cor[$r->event]) ? $cor[$r->event] : '#555';
        echo '<tr>';
        echo '<td>' . esc_html($r->created_at) . '</td>';
        echo '<td><strong style="color:' . esc_attr($c) . '">' . esc_html($r->event) . '</strong></td>';
        echo '<td>' . esc_html($r->gateway) . '</td>';
        echo '<td><code>' . esc_html($r->payment_id) . '</code></td>';
        echo '<td>' . esc_html($r->wp_user_id ?: '') . '</td>';
        echo '<td>' . esc_html($r->email) . '</td>';
        echo '<td>' . esc_html($r->produto_id) . '</td>';
        echo '<td>' . ($r->amount > 0 ? esc_html(number_format((float)$r->amount, 2, ',', '.')) : '') . '</td>';
        echo '<td>' . esc_html($r->status) . '</td>';
        echo '<td style="max-width:340px;font-size:11px;color:#666">' . esc_html($r->detail) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

