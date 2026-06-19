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
    $interest_rates = array(1 => 0, 2 => 7, 3 => 8, 4 => 9, 5 => 10, 6 => 11, 7 => 13, 8 => 14, 9 => 15, 10 => 16, 11 => 18, 12 => 20);
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
        'cer' => array(
            'nome' => 'Formação CER',
            'membership_id' => 12219,
            'preco_brl' => 1997.00,
            'preco_usd' => 397.00,
            'preco_eur' => 347.00
        ),
        'infinity' => array(
            'nome' => 'Infinity Reiki',
            'membership_id' => 12224,
            'preco_brl' => 67.00,
            'preco_usd' => 17.00,
            'preco_eur' => 17.00
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
    register_rest_route( 'reiki/v1', '/coupon', array(
        'methods' => 'GET, OPTIONS',
        'callback' => 'validar_cupom_woocommerce',
        'permission_callback' => '__return_true'
    ) );
} );

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
    $bumps_selecionados = isset($params['bumps']) && is_array($params['bumps']) ? array_map('sanitize_text_field', $params['bumps']) : array();
    
    // Configuração dos Bumps
    $bumps_config = array(
        '12224_ext' => array('nome' => '+6 Meses', 'brl' => 19.90, 'usd' => 5.00, 'eur' => 5.00),
        '13031'     => array('nome' => 'Desafio Infinity', 'brl' => 47.00, 'usd' => 10.00, 'eur' => 10.00),
        '12895'     => array('nome' => 'Deusa AI PRO', 'brl' => 29.90, 'usd' => 6.00, 'eur' => 6.00)
    );

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
        $cep = sanitize_text_field( $params['cep'] );
        $numero = sanitize_text_field( $params['numero'] );

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
            
            conceder_acesso_curso($wp_user_id, $produto_id);
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
            
            // Cartão pré-autorizado! Esconde o ID do Cartão na externalReference do PIX
            $pix_external_ref = $external_ref_base . '|' . $resp_card['id']; 
            
            $resp_pix = reiki_asaas_cobranca($customer_id, 'PIX', $pix_val, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Parte PIX)', $pix_external_ref);
            if (is_wp_error($resp_pix)) {
                reiki_asaas_cancelar($resp_card['id']);
                return $resp_pix;
            }
            
            $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp_pix['id'] . '/pixQrCode', array('headers' => $headers) );
            $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
            $retorno['pix_qrcode'] = $pix_data['encodedImage'];
            $retorno['pix_copia_cola'] = $pix_data['payload'];
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
            $pix_external_ref = $external_ref_base . '|PIX_ENTRY_IGNORE';
            
            $resp_pix = reiki_asaas_cobranca($customer_id, 'PIX', $pix_val, date('Y-m-d', strtotime('+1 days')), $descricao_compra_final . ' (Entrada PIX)', $pix_external_ref);
            if (is_wp_error($resp_pix)) {
                reiki_asaas_cancelar($resp_boleto['id']);
                return $resp_pix;
            }
            
            $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp_pix['id'] . '/pixQrCode', array('headers' => $headers) );
            $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
            $retorno['pix_qrcode'] = $pix_data['encodedImage'];
            $retorno['pix_copia_cola'] = $pix_data['payload'];
            $retorno['boleto_url'] = $resp_boleto['bankSlipUrl'];
            $retorno['status_venda'] = 'PENDING';

        } else {
            // Fluxo NORMAL
            $billing_type = strtoupper($metodo);
            $cartao_extra = null;
            $due_date = date('Y-m-d', strtotime('+2 days'));
            if ($billing_type === 'PIX') $due_date = date('Y-m-d', strtotime('+1 days'));
            
            if ($billing_type === 'CREDIT_CARD') {
                $due_date = date('Y-m-d');
                $params['parcelas'] = intval($params['parcelas']);
                $cartao_extra = reiki_asaas_montar_cartao($params, $valor_total, $nome, $email, $cpf, $telefone, $ip_cliente, $cep, $numero);
            }
            
            $resp = reiki_asaas_cobranca($customer_id, $billing_type, $valor_total, $due_date, $descricao_compra_final, $external_ref_base, $cartao_extra, false);
            if (is_wp_error($resp)) return $resp;
            
            $retorno['status_venda'] = $resp['status'];
            
            if ($billing_type == 'PIX') {
                $pix_qr = wp_remote_get( $base_url . '/payments/' . $resp['id'] . '/pixQrCode', array('headers' => $headers) );
                $pix_data = json_decode( wp_remote_retrieve_body( $pix_qr ), true );
                $retorno['pix_qrcode'] = $pix_data['encodedImage'];
                $retorno['pix_copia_cola'] = $pix_data['payload'];
            }

            if ( in_array($resp['status'], array('CONFIRMED', 'RECEIVED')) ) {
                conceder_acesso_curso($wp_user_id, $produto_id);
                if (!empty($bumps_selecionados)) processar_acesso_bumps($wp_user_id, $bumps_selecionados);
                if ($cupom_id_queimado > 0 && function_exists('wc_update_coupon_usage_counts')) wc_update_coupon_usage_counts( $cupom_id_queimado );
            }
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
            conceder_acesso_curso($wp_user_id, $produto_id);
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
            if ( count($parts) >= 2 ) {
                $wp_user_id = intval($parts[0]);
                $produto_id = sanitize_text_field($parts[1]);
                
                // Pagamento Híbrido: Captura o cartão se existir ID atrelado
                if (isset($parts[4]) && !empty($parts[4])) {
                    $capture_card_id = sanitize_text_field($parts[4]);
                    $cap_result = reiki_asaas_capturar($capture_card_id);
                    if (is_wp_error($cap_result)) {
                        error_log('ALERTA: Captura do cartão ' . $capture_card_id . ' falhou no webhook Pix+Cartão');
                    }
                }

                conceder_acesso_curso($wp_user_id, $produto_id);
                
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
        // Pagamento Híbrido: Se o PIX expirar, cancela o hold do cartão
        $payment = $payload['payment'];
        if ( !empty($payment['externalReference']) && $payment['billingType'] === 'PIX' ) {
            $parts = explode('|', $payment['externalReference']);
            if ( count($parts) >= 5 ) {
                $capture_card_id = sanitize_text_field($parts[4]);
                reiki_asaas_cancelar($capture_card_id);
                error_log('Webhook Híbrido: PIX expirado/deletado. Autorização do cartão ' . $capture_card_id . ' cancelada.');
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
        if ( $plan_id > 0 && !wc_memberships_is_user_active_member($wp_user_id, $plan_id) ) {
            $args = array(
                'plan_id' => $plan_id,
                'user_id' => $wp_user_id
            );
            wc_memberships_create_user_membership( $args );
        }
        
        $user_info = get_userdata($wp_user_id);
        $nome = $user_info ? $user_info->first_name : '';
        $email = $user_info ? $user_info->user_email : '';
        if ($email) {
             ead_enviar_email_boas_vindas($email, $nome, $wp_user_id, $produto_id, 'matricular');
        }
    }
}

function processar_acesso_bumps($wp_user_id, $bumps_array) {
    if ( !function_exists('wc_memberships_create_user_membership') ) return;
    
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
