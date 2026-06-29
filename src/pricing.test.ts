import { describe, it, expect } from 'vitest';
import {
  INTEREST_RATES,
  bumpPrice,
  computeBasePrice,
  computeTotal,
  type Bump,
} from './pricing';

const BUMPS: Bump[] = [
  { id: '12224_ext', brlPrice: 19.9, usdPrice: 5, eurPrice: 5 },
  { id: '13031', brlPrice: 47, usdPrice: 10, eurPrice: 10 },
  { id: '12895', brlPrice: 29.9, usdPrice: 6, eurPrice: 6 },
];

const base = (over = {}) => ({
  productPrice: 1997,
  productId: 'cer',
  currency: 'BRL' as const,
  bumps: BUMPS,
  selectedBumps: [] as string[],
  couponDiscount: null,
  ...over,
});

const total = (over = {}) =>
  computeTotal({
    basePrice: 1997,
    currency: 'BRL',
    paymentMethod: 'credit_card',
    installments: 1,
    installments2: 1,
    rates: INTEREST_RATES,
    card1EntryValue: 0,
    pixEntryValue: 0,
    ...over,
  });

describe('INTEREST_RATES (deve casar com o backend)', () => {
  it('tem as taxas oficiais', () => {
    expect(INTEREST_RATES[1]).toBe(0);
    expect(INTEREST_RATES[6]).toBe(12.8);
    expect(INTEREST_RATES[12]).toBe(24.1);
  });
});

describe('bumpPrice', () => {
  it('escolhe o preço por moeda', () => {
    expect(bumpPrice(BUMPS[1], 'BRL')).toBe(47);
    expect(bumpPrice(BUMPS[1], 'USD')).toBe(10);
    expect(bumpPrice(BUMPS[1], 'EUR')).toBe(10);
  });
});

describe('computeBasePrice', () => {
  it('produto sem bump nem cupom', () => {
    expect(computeBasePrice(base())).toBe(1997);
  });

  it('bumps só contam no Infinity', () => {
    // produto != infinity => bumps ignorados
    expect(computeBasePrice(base({ selectedBumps: ['13031'] }))).toBe(1997);
    // infinity => soma os bumps
    expect(
      computeBasePrice(base({ productId: 'infinity', productPrice: 67, selectedBumps: ['13031', '12895'] })),
    ).toBeCloseTo(67 + 47 + 29.9, 2);
  });

  it('cupom percentual', () => {
    expect(computeBasePrice(base({ couponDiscount: { type: 'percent', amount: 10 } }))).toBeCloseTo(1797.3, 2);
  });

  it('cupom fixo só em BRL', () => {
    expect(computeBasePrice(base({ couponDiscount: { type: 'fixed', amount: 200 } }))).toBe(1797);
    // em USD, cupom fixo é ignorado
    expect(
      computeBasePrice(base({ currency: 'USD', productPrice: 397, couponDiscount: { type: 'fixed', amount: 200 } })),
    ).toBe(397);
  });

  it('nunca fica negativo', () => {
    expect(computeBasePrice(base({ couponDiscount: { type: 'fixed', amount: 99999 } }))).toBe(0);
  });
});

describe('computeTotal — cartão de crédito (juros)', () => {
  it('1x não tem juros', () => {
    expect(total({ installments: 1 })).toBe(1997);
  });

  it('CER 12x = 1997 * 1.241', () => {
    expect(total({ installments: 12 })).toBeCloseTo(2478.277, 2);
    // a parcela exibida no checkout
    expect(total({ installments: 12 }) / 12).toBeCloseTo(206.52, 2);
  });

  it('Guardias 12x', () => {
    expect(total({ basePrice: 997, installments: 12 })).toBeCloseTo(1237.277, 2);
  });

  it('Reiki Florescer 12x', () => {
    expect(total({ basePrice: 297, installments: 12 })).toBeCloseTo(368.577, 2);
  });
});

describe('computeTotal — combinados', () => {
  it('2 cartões: cada parte com seus juros', () => {
    // 1000 em 1x (sem juros) + 997 em 12x (24.1%)
    const t = total({ paymentMethod: 'two_cards', basePrice: 1997, card1EntryValue: 1000, installments: 1, installments2: 12 });
    expect(t).toBeCloseTo(1000 + 997 * 1.241, 2);
  });

  it('pix + cartão: pix à vista + cartão com juros', () => {
    const t = total({ paymentMethod: 'pix_and_card', basePrice: 1997, pixEntryValue: 500, installments: 12 });
    expect(t).toBeCloseTo(500 + 1497 * 1.241, 2);
  });

  it('pix + boleto: sem juros', () => {
    expect(total({ paymentMethod: 'pix_and_boleto', basePrice: 1997, pixEntryValue: 500 })).toBe(1997);
  });
});

describe('computeTotal — internacional (sem juros BRL)', () => {
  it('USD/EUR retornam o basePrice', () => {
    expect(total({ currency: 'USD', basePrice: 397, installments: 12 })).toBe(397);
    expect(total({ currency: 'EUR', basePrice: 347, installments: 12 })).toBe(347);
  });
});
