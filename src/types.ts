// Tipos dos payloads e respostas das APIs do checkout (T4.2).
// Centraliza o "contrato" com o backend — se um campo mudar de nome, o TS acusa.
import type { Stripe, StripeElements } from '@stripe/stripe-js';

export interface CardData {
  value?: number;
  cc_name: string;
  cc_number: string;
  cc_expiry: string;
  cc_cvv: string;
  parcelas: number;
}

export interface CheckoutPayload {
  nome: string;
  email: string;
  telefone: string;
  cpf: string;
  produto: string;
  valorTotal: number;
  gateway: string; // 'asaas' | 'stripe' | 'stripe_update'
  currency: string;
  parcelas: number;
  parcelas_carne?: number;
  metodo: string;
  bumps: string[];
  turnstileToken: string | null;
  cupom: string;
  // adicionados condicionalmente conforme o método:
  payment_intent_id?: string;
  cc_name?: string;
  cc_number?: string;
  cc_expiry?: string;
  cc_cvv?: string;
  cep?: string;
  numero?: string;
  cards?: CardData[];
  card?: CardData;
  pix_value?: number;
}

export interface CheckoutResponse {
  sucesso?: boolean;
  message?: string;
  metodo?: string;
  status_venda?: string;
  pix_qrcode?: string;
  pix_copia_cola?: string;
  boleto_url?: string;
  payment_id?: string;
  requires_action?: boolean;
  client_secret?: string;
  wp_user_id?: number;
}

export interface StripeIntentResponse {
  client_secret?: string;
  payment_intent_id?: string;
}

export interface CouponResponse {
  sucesso?: boolean;
  tipo?: string;
  valor?: number;
  message?: string;
}

export interface CustomLinkResponse {
  sucesso?: boolean;
  status?: string; // active | off | espera

  title?: string;
  subtitle?: string;
  brlPrice?: number;
  usdPrice?: number;
  eurPrice?: number;
  image?: string;
  is_carne?: boolean;
  is_subscription?: boolean;
  parcelas_carne?: number;
}

export interface PaymentStatusResponse {
  paid?: boolean;
  status?: string;
}

export interface ViaCepResponse {
  erro?: boolean;
  logradouro?: string;
  bairro?: string;
  localidade?: string;
  uf?: string;
}

export interface CatalogProduct {
  nome: string;
  preco_brl: number;
  preco_usd: number;
  preco_eur: number;
}

export interface CatalogBump {
  nome: string;
  brl: number;
  usd: number;
  eur: number;
}

export interface CatalogResponse {
  version?: string;
  products?: Record<string, CatalogProduct>;
  interest_rates?: Record<string, number>;
  bumps?: Record<string, CatalogBump>;
}

// Handle imperativo exposto pelo StripeInner (ref do formulário Stripe).
export interface StripeHandle {
  confirm: () => Promise<{
    error?: { message?: string };
    stripe?: Stripe;
    elements?: StripeElements;
  }>;
}
