// Lógica PURA de preço/juros do checkout. Sem dependências de React/DOM — testável isoladamente.
// É a fonte única usada pelo App.tsx; os testes (pricing.test.ts) travam essa matemática.

export type Currency = 'BRL' | 'USD' | 'EUR';
export type PaymentMethod =
  | 'credit_card' | 'pix' | 'boleto'
  | 'two_cards' | 'pix_and_card' | 'pix_and_boleto' | 'boleto_parcelado';

export interface Bump {
  id: string;
  brlPrice: number;
  usdPrice: number;
  eurPrice: number;
}

export interface CouponDiscount {
  type: string; // 'percent' | 'fixed'
  amount: number;
}

// Tabela OFICIAL de juros do parcelamento (cartão BRL).
// ATENÇÃO: deve ser IDÊNTICA ao $interest_rates do backend (reiki_asaas_montar_cartao)
// e ao que o /catalog devolve. Se mudar, mude nos dois lugares.
export const INTEREST_RATES: Record<number, number> = {
  1: 0, 2: 5.3, 3: 7.1, 4: 9.0, 5: 10.9, 6: 12.8,
  7: 14.7, 8: 16.7, 9: 18.7, 10: 20.2, 11: 22.2, 12: 24.1,
};

export function bumpPrice(bump: Bump, currency: Currency): number {
  if (currency === 'USD') return bump.usdPrice;
  if (currency === 'EUR') return bump.eurPrice;
  return bump.brlPrice;
}

// Preço base: produto + order bumps (só no Infinity) + cupom.
export function computeBasePrice(opts: {
  productPrice: number;
  productId: string;
  currency: Currency;
  bumps: Bump[];
  selectedBumps: string[];
  couponDiscount: CouponDiscount | null;
}): number {
  let price = opts.productPrice;

  if (opts.productId === 'infinity') {
    for (const id of opts.selectedBumps) {
      const b = opts.bumps.find((x) => x.id === id);
      if (b) price += bumpPrice(b, opts.currency);
    }
  }

  if (opts.couponDiscount) {
    if (opts.couponDiscount.type === 'percent') {
      price = price - price * (opts.couponDiscount.amount / 100);
    } else if (opts.currency === 'BRL') {
      // desconto fixo só aplica em BRL (moeda base)
      price = price - opts.couponDiscount.amount;
    }
    if (price < 0) price = 0;
  }

  return price;
}

// Total final conforme o método de pagamento (aplica juros do cartão quando há parcelamento).
export function computeTotal(opts: {
  basePrice: number;
  currency: Currency;
  paymentMethod: PaymentMethod;
  installments: number;
  installments2: number;
  rates: Record<number, number>;
  card1EntryValue: number; // valor no 1º cartão (two_cards)
  pixEntryValue: number; // entrada no PIX (pix_and_card / pix_and_boleto)
}): number {
  const { basePrice, currency, paymentMethod, installments, installments2, rates } = opts;

  if (currency === 'BRL') {
    if (paymentMethod === 'credit_card') {
      const rate = rates[installments] || 0;
      return basePrice * (1 + rate / 100);
    }
    if (paymentMethod === 'two_cards') {
      const val1 = opts.card1EntryValue || 0;
      const val2 = Math.max(0, basePrice - val1);
      const rate1 = rates[installments] || 0;
      const rate2 = rates[installments2] || 0;
      return val1 * (1 + rate1 / 100) + val2 * (1 + rate2 / 100);
    }
    if (paymentMethod === 'pix_and_card') {
      const pixVal = opts.pixEntryValue || 0;
      const cardVal = Math.max(0, basePrice - pixVal);
      const rate = rates[installments] || 0;
      return pixVal + cardVal * (1 + rate / 100);
    }
    if (paymentMethod === 'pix_and_boleto') {
      return basePrice; // boleto à vista, sem juros
    }
  }
  return basePrice;
}
