<?php
/**
 * REIKI TIME ACADEMY - CHECKOUT UNIVERSAL + WEBHOOKS
 * Este código deve ser colado no WPCode do site ead.reikitimeacademy.com.br
 * VERSÃO REAL EM PRODUÇÃO (chaves removidas por segurança)
 */

// 1. Configurações Iniciais do ASAAS
define('REIKI_ASAAS_API_KEY', ''); // Chave removida
define('REIKI_ASAAS_WEBHOOK_TOKEN', ''); // Token removido
define('ASAAS_IS_SANDBOX', false);

// 2. Configurações Iniciais do STRIPE
define('REIKI_STRIPE_SECRET_KEY', ''); // Chave removida
define('REIKI_STRIPE_WEBHOOK_SECRET', ''); // <--- Coloque o Signing Secret (whsec_...) aqui

// 3. Catálogo de Produtos e IDs do WooCommerce Memberships
function get_reiki_products() {
    return array(
        'cuidar' => array(
            'nome' => 'Formação Método CUIDAR',
            'membership_id' => 15098,
            'preco_brl' => 647.00,
            'preco_usd' => 129.00,
            'preco_eur' => 119.00
        ),
        'ebook' => array(
            'nome' => 'E-book Reiki Essencial',
            'membership_id' => 5678,
            'preco_brl' => 47.00,
            'preco_usd' => 9.00,
            'preco_eur' => 8.00
        )
    );
}

// =========================================================================
// ROTAS DA API
// =========================================================================
add_action( 'rest_api_init', function () {
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
} );

// =========================================================================
// 1. CHECKOUT FRONTEND
// =========================================================================
function processar_checkout_universal( WP_REST_Request $request ) {
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

    $gateway = sanitize_text_field( $params['gateway'] );
    $nome = sanitize_text_field( $params['nome'] );
    $email = sanitize_email( $params['email'] );
    $produto_id = sanitize_text_field( $params['produto'] );
    // SEGURANÇA: Preço vem do catálogo do servidor, nunca do frontend
    $currency = strtolower(sanitize_text_field( $params['currency'] ?? 'brl' ));
    $produto_info = isset($produtos[$produto_id]) ? $produtos[$produto_id] : null;
    if ($produto_info) {
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

    if ( empty($nome) || empty($email) || empty($produto_id) ) {
        return new WP_Error( 'erro_dados', 'Preencha todos os dados obrigatórios', array( 'status' => 400 ) );
    }

    // --- RATE LIMITING ---
    $ip_cliente = $_SERVER['REMOTE_ADDR'];
    $transient_name = 'rate_limit_' . md5($ip_cliente);
    $tentativas = get_transient( $transient_name ) ?: 0;
    if ( $tentativas > 5 ) return new WP_Error( 'rate_limit', 'Muitas tentativas.', array( 'status' => 429 ) );
    set_transient( $transient_name, $tentativas + 1, 60 * 15 );

    if ( !isset( $produtos[$produto_id] ) ) {
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
        $parcelas = intval( $params['parcelas'] );

        $base_url = ASAAS_IS_SANDBOX ? 'https://sandbox.asaas.com/api/v3' : 'https://api.asaas.com/v3';
        $headers = array('Content-Type' => 'application/json', 'access_token' => REIKI_ASAAS_API_KEY);

        $customer_id = buscar_ou_criar_cliente_asaas($nome, $email, $cpf, $telefone, $base_url, $headers);
        if ( is_wp_error( $customer_id ) ) return $customer_id;

        $billing_type = strtoupper($metodo);
        
        $body = array(
            'customer' => $customer_id,
            'billingType' => $billing_type,
            'value' => $valor_total,
            'dueDate' => date('Y-m-d', strtotime('+2 days')),
            'description' => 'Compra: ' . $produtos[$produto_id]['nome'],
            'externalReference' => $wp_user_id . '|' . $produto_id
        );

        if ($billing_type == 'CREDIT_CARD') {
            $body['dueDate'] = date('Y-m-d');
            $body['installmentCount'] = $parcelas;
            
            // Aplica tabela de juros para cartão parcelado
            $interest_rates = array(1 => 0, 2 => 7, 3 => 8, 4 => 9, 5 => 10, 6 => 12, 7 => 13, 8 => 14, 9 => 15, 10 => 16, 11 => 18, 12 => 20);
            $rate = isset($interest_rates[$parcelas]) ? $interest_rates[$parcelas] : 0;
            $valor_com_juros = $valor_total * (1 + $rate / 100);
            $body['installmentValue'] = round($valor_com_juros / $parcelas, 2);
            
            $cc_expiry = sanitize_text_field( $params['cc_expiry'] );
            $exp_parts = explode('/', $cc_expiry);
            $body['creditCard'] = array(
                'holderName' => sanitize_text_field( $params['cc_name'] ),
                'number' => preg_replace('/[^0-9]/', '', $params['cc_number']),
                'expiryMonth' => trim($exp_parts[0]),
                'expiryYear' => (strlen(trim($exp_parts[1])) == 2) ? '20'.trim($exp_parts[1]) : trim($exp_parts[1]),
                'ccv' => sanitize_text_field( $params['cc_cvv'] )
            );
            $body['creditCardHolderInfo'] = array(
                'name' => $nome, 'email' => $email, 'cpfCnpj' => preg_replace('/[^0-9]/', '', $cpf),
                'postalCode' => sanitize_text_field( $params['cep'] ),
                'addressNumber' => sanitize_text_field( $params['numero'] ),
                'phone' => preg_replace('/[^0-9]/', '', $telefone),
                'remoteIp' => $ip_cliente
            );
        }

        $response = wp_remote_post( $base_url . '/payments', array('headers' => $headers, 'body' => json_encode($body), 'timeout' => 30) );
        if ( is_wp_error( $response ) ) return new WP_Error( 'erro_api', 'Falha ao conectar com banco.', array( 'status' => 500 ) );

        $body_resp = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset($body_resp['errors']) ) return new WP_Error( 'recusado', $body_resp['errors'][0]['description'], array( 'status' => 400 ) );

        $retorno['sucesso'] = true;
        $retorno['metodo'] = $billing_type;
        $retorno['status_venda'] = $body_resp['status'];

        if ($billing_type == 'PIX') {
            $pix_resp = wp_remote_get( $base_url . '/payments/' . $body_resp['id'] . '/pixQrCode', array('headers' => $headers) );
            $pix_data = json_decode( wp_remote_retrieve_body( $pix_resp ), true );
            $retorno['pix_qrcode'] = $pix_data['encodedImage'];
            $retorno['pix_copia_cola'] = $pix_data['payload'];
        }

        if ( in_array($body_resp['status'], array('CONFIRMED', 'RECEIVED')) ) {
            conceder_acesso_curso($wp_user_id, $produto_id);
        }

    // ================== FLUXO STRIPE (INTERNACIONAL) ==================
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
            'description' => 'Compra: ' . $produtos[$produto_id]['nome'],
            'receipt_email' => $email,
            'metadata[wp_user_id]' => $wp_user_id,
            'metadata[produto_id]' => $produto_id
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
            conceder_acesso_curso($wp_user_id, $produto_id);
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
    $token_enviado = $request->get_header('asaas-access-token');
    
    // SEGURANÇA: Rejeita se o token não estiver configurado no servidor ou se o enviado não bater
    if ( !defined('REIKI_ASAAS_WEBHOOK_TOKEN') || REIKI_ASAAS_WEBHOOK_TOKEN === '' || $token_enviado !== REIKI_ASAAS_WEBHOOK_TOKEN ) {
        return new WP_Error( 'nao_autorizado', 'Token de Webhook inválido ou não configurado', array( 'status' => 401 ) );
    }

    $payload = $request->get_json_params();

    if ( isset($payload['event']) && in_array($payload['event'], array('PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED')) ) {
        $payment = $payload['payment'];
        if ( !empty($payment['externalReference']) ) {
            $parts = explode('|', $payment['externalReference']);
            if ( count($parts) == 2 ) {
                $wp_user_id = intval($parts[0]);
                $produto_id = sanitize_text_field($parts[1]);
                conceder_acesso_curso($wp_user_id, $produto_id);
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

    if ( !in_array($expected_sig, $signatures) ) {
        return new WP_Error( 'assinatura_invalida', 'Assinatura do Stripe inválida', array( 'status' => 401 ) );
    }

    $payload = json_decode($payload_raw, true);

    if ( isset($payload['type']) && $payload['type'] === 'payment_intent.succeeded' ) {
        $payment_intent = $payload['data']['object'];
        if ( !empty($payment_intent['metadata']['wp_user_id']) && !empty($payment_intent['metadata']['produto_id']) ) {
            $wp_user_id = intval($payment_intent['metadata']['wp_user_id']);
            $produto_id = sanitize_text_field($payment_intent['metadata']['produto_id']);
            conceder_acesso_curso($wp_user_id, $produto_id);
        }
    }

    return rest_ensure_response( array('recebido' => true) );
}


// =========================================================================
// FUNÇÕES AUXILIARES
// =========================================================================
function conceder_acesso_curso($wp_user_id, $produto_id) {
    $produtos = get_reiki_products();
    if ( !function_exists('wc_memberships_create_user_membership') ) {
        error_log('ALERTA REIKI CHECKOUT: Plugin WooCommerce Memberships inativo! Não foi possível conceder acesso ao usuário ID ' . $wp_user_id);
        return;
    }
    
    if ( isset($produtos[$produto_id]) ) {
        $plan_id = $produtos[$produto_id]['membership_id'];
        if ( !wc_memberships_is_user_active_member($wp_user_id, $plan_id) ) {
            $args = array(
                'plan_id' => $plan_id,
                'user_id' => $wp_user_id
            );
            wc_memberships_create_user_membership( $args );
        }
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
