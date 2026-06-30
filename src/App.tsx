import React, { useState, useEffect, useRef, forwardRef, useImperativeHandle } from 'react';
import { CreditCard, QrCode, Receipt, ShieldCheck, Lock, Globe, Loader2, CheckCircle2 } from 'lucide-react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { Turnstile } from '@marsidev/react-turnstile';
import type { TurnstileInstance } from '@marsidev/react-turnstile';
import { INTEREST_RATES, computeBasePrice, computeTotal } from './pricing';
import SuccessScreen from './SuccessScreen';
import type {
  CheckoutPayload, CheckoutResponse, StripeIntentResponse, CouponResponse,
  CustomLinkResponse, PaymentStatusResponse, ViaCepResponse, CatalogResponse, StripeHandle,
} from './types';

// Helper: fetch com timeout (AbortController) — evita travar em "Processando..." se um gateway engasgar.
async function fetchWithTimeout(url: string, opts: RequestInit = {}, ms = 25000): Promise<Response> {
  const ctrl = new AbortController();
  const id = setTimeout(() => ctrl.abort(), ms);
  try {
    return await fetch(url, { ...opts, signal: ctrl.signal });
  } finally {
    clearTimeout(id);
  }
}

// Chave Pública do Stripe (Pegue no Painel da Reiki Time Academy)
const stripePromise = loadStripe('pk_live_51M4X8UJCAiZy2d8TofsRm9xrvVjItsdR1XycJvZFG1vYuYbecbGZLuGGpQLqVLUITrLon7v7g0Rz0Q0sK5zJSXSF006dJtMiSx');

const StripeInner = forwardRef((_, ref) => {
   const stripe = useStripe();
   const elements = useElements();
   useImperativeHandle(ref, () => ({
       confirm: async () => {
           if (!stripe || !elements) return { error: { message: "Stripe indisponível" } };
           const { error: submitError } = await elements.submit();
           if (submitError) return { error: submitError };
           return { stripe, elements };
       }
   }));
   return <PaymentElement />;
});

const StripeWrapper = forwardRef(({ clientSecret }: { clientSecret: string }, ref) => {
   if (!clientSecret) return <div className="text-stone-500 text-sm">Carregando carteiras seguras...</div>;
   return (
      <Elements stripe={stripePromise} options={{ clientSecret, appearance: { theme: 'stripe' } }}>
         <StripeInner ref={ref} />
      </Elements>
   );
});

// INTEREST_RATES, computeBasePrice e computeTotal agora vêm de ./pricing (fonte única + testada).

const PRODUCTS: Record<string, { title: string, subtitle: string, brlPrice: number, brlOriginal: number, usdPrice: number, eurPrice: number, image: string, is_carne?: boolean, parcelas_carne?: number, is_subscription?: boolean }> = {
  'guardias': { 
    title: 'Formação Guardiãs do Clã', 
    subtitle: 'Turma Fundadora', 
    brlPrice: 997, 
    brlOriginal: 1297, 
    usdPrice: 200, 
    eurPrice: 175, 
    image: 'https://reikitimeacademy.com.br/wp-content/uploads/2026/03/guardias-hero-bg.png' 
  },
  'cuidar': { 
    title: 'Formação Método CUIDAR', 
    subtitle: 'Acesso completo + Bônus exclusivos',
    brlPrice: 697.00, brlOriginal: 997.00,
    usdPrice: 145.00, eurPrice: 125.00,
    image: '/curso-cuidar.png'
  },
  'cer': {
    title: 'Certificação Expert Reiki Completa',
    subtitle: 'Curso Expert Reiki V2 + Bônus Físicos e Digitais',
    brlPrice: 1997.00, brlOriginal: 5188.00,
    usdPrice: 397.00, eurPrice: 347.00,
    image: '/cer.png'
  },
  'infinity': {
    title: 'Método Infinity Reiki',
    subtitle: 'Acesso completo por 6 meses',
    brlPrice: 67.00, brlOriginal: 697.00,
    usdPrice: 17.00, eurPrice: 17.00,
    image: '/infinity.png'
  },
  'reiki-florescer': {
    title: 'Reiki Florescer',
    subtitle: 'Acesso completo',
    brlPrice: 297.00, brlOriginal: 497.00,
    usdPrice: 60.00, eurPrice: 55.00,
    image: '/infinity.png' // default image until they have a specific one
  },
  'desafio-infinity': {
    title: 'Desafio Infinity',
    subtitle: 'Imersão prática de 3 dias',
    brlPrice: 67.00, brlOriginal: 697.00,
    usdPrice: 17.00, eurPrice: 17.00,
    image: '/infinity.png'
  },
  'ebook': { 
    title: 'E-book Reiki Essencial', 
    subtitle: 'Download imediato (PDF)',
    brlPrice: 29.90, brlOriginal: 97.00,
    usdPrice: 6.00, eurPrice: 6.00,
    image: '/curso-cuidar.png'
  }
};

const ORDER_BUMPS = [
  {
    id: '12224_ext', // ID temporário que usaremos no backend para identificar
    title: 'Sim! Quero mais 6 meses de acesso',
    brlPrice: 19.90, brlOriginal: 67.00,
    usdPrice: 5.00, usdOriginal: 17.00,
    eurPrice: 5.00, eurOriginal: 17.00,
    desc: 'Garanta 1 ano completo de acesso à plataforma, aulas e comunidade, sem interrupção.'
  },
  {
    id: '13031', // Desafio Infinity
    title: 'Sim! Quero o Desafio Infinity e vender Reiki em 3 semanas',
    brlPrice: 47.00, brlOriginal: 497.00,
    usdPrice: 10.00, usdOriginal: 97.00,
    eurPrice: 10.00, eurOriginal: 87.00,
    desc: 'O passo a passo da metodologia Infinity aplicada a vendas com acompanhamento.'
  },
  {
    id: '12895', // Deusa AI PRO
    title: 'Sim! Quero adicionar 10 créditos Deusa AI PRO',
    brlPrice: 29.90, brlOriginal: 0,
    usdPrice: 6.00, usdOriginal: 0,
    eurPrice: 6.00, eurOriginal: 0,
    desc: 'Gere um mapeamento energético profundo (Chakras ou Mapa EEF) em minutos.'
  }
];

type PaymentMethod = 'credit_card' | 'pix' | 'boleto' | 'two_cards' | 'pix_and_card' | 'pix_and_boleto' | 'boleto_parcelado';
type Currency = 'BRL' | 'USD' | 'EUR';

function isValidCPF(cpf: string) {
  cpf = cpf.replace(/[^\d]+/g, '');
  if (cpf.length !== 11 || !!cpf.match(/(\d)\1{10}/)) return false;
  let t = 0, d = 0, c;
  for (c = 0; c < 9; c++) t += parseInt(cpf.charAt(c)) * (10 - c);
  d = 11 - (t % 11);
  if (d > 9) d = 0;
  if (parseInt(cpf.charAt(9)) !== d) return false;
  t = 0;
  for (c = 0; c < 10; c++) t += parseInt(cpf.charAt(c)) * (11 - c);
  d = 11 - (t % 11);
  if (d > 9) d = 0;
  if (parseInt(cpf.charAt(10)) !== d) return false;
  return true;
}

function isValidLuhn(number: string) {
  const digits = number.replace(/\D/g, '');
  if (digits.length < 13) return false;
  let sum = 0;
  let isEven = false;
  for (let i = digits.length - 1; i >= 0; i--) {
    let digit = parseInt(digits.charAt(i), 10);
    if (isEven) {
      digit *= 2;
      if (digit > 9) digit -= 9;
    }
    sum += digit;
    isEven = !isEven;
  }
  return sum % 10 === 0;
}

// ----------------------------------------------------------------------
// FORMULÁRIO PRINCIPAL DE CHECKOUT (Envolto pelo Stripe Elements)
// ----------------------------------------------------------------------
function CheckoutForm() {
  const stripeRef = useRef<StripeHandle | null>(null);
  const [clientSecret, setClientSecret] = useState('');
  const [paymentIntentId, setPaymentIntentId] = useState('');

  const [currency, setCurrency] = useState<Currency>(() => {
    const urlCurrency = new URLSearchParams(window.location.search).get('currency') as Currency;
    if (urlCurrency && ['BRL', 'USD', 'EUR'].includes(urlCurrency)) return urlCurrency;
    return 'BRL';
  });
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('credit_card');
  const [installments, setInstallments] = useState<number>(1);
  
  // PII (nome/email/telefone) NÃO é mais lida da URL — o cliente preenche no próprio checkout.
  // Evita vazar dado pessoal via Referer/logs/histórico (decisão de privacidade da V1).
  const [nome, setNome] = useState('');
  const [email, setEmail] = useState('');
  const [telefone, setTelefone] = useState('');
  const [cpf, setCpf] = useState('');
  
  const [ccNumber, setCcNumber] = useState('');
  const [ccName, setCcName] = useState('');
  const [ccExpiry, setCcExpiry] = useState('');
  const [ccCvv, setCcCvv] = useState('');

  const [ccNumber2, setCcNumber2] = useState('');
  const [ccName2, setCcName2] = useState('');
  const [ccExpiry2, setCcExpiry2] = useState('');
  const [ccCvv2, setCcCvv2] = useState('');
  const [installments2, setInstallments2] = useState<number>(1);

  const [pixEntryValue, setPixEntryValue] = useState<string>('');
  const [card1EntryValue, setCard1EntryValue] = useState<string>('');

  const [cep, setCep] = useState('');
  const [endereco, setEndereco] = useState('');
  const [numero, setNumero] = useState('');

  const [errors, setErrors] = useState<Record<string, string>>({});
  const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
  const turnstileRef = useRef<TurnstileInstance | null>(null);
  const [agreedTerms, setAgreedTerms] = useState(true);
  const [agreedImmediateDelivery, setAgreedImmediateDelivery] = useState(true);
  
  const [selectedBumps, setSelectedBumps] = useState<string[]>([]);

  const toggleBump = (bumpId: string) => {
    setSelectedBumps(prev => 
      prev.includes(bumpId) ? prev.filter(id => id !== bumpId) : [...prev, bumpId]
    );
  };
  
  useEffect(() => {
    const cleanCep = cep.replace(/\D/g, '');
    if (cleanCep.length === 8) {
      fetchWithTimeout(`https://viacep.com.br/ws/${cleanCep}/json/`, {}, 8000)
        .then(res => res.json())
        .then((data: ViaCepResponse) => {
          if (!data.erro) {
            setEndereco(`${data.logradouro}, ${data.bairro} - ${data.localidade}/${data.uf}`);
          }
        })
        .catch(() => {});
    } else {
      setEndereco('');
    }
  }, [cep]);

  // Status de Transação (Carregamento e Sucesso)
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pixData, setPixData] = useState<{ qrcode: string, copia_cola: string } | null>(null);
  const [boletoUrl, setBoletoUrl] = useState<string | null>(null);
  const [paymentId, setPaymentId] = useState<string | null>(null);
  const [paymentConfirmed, setPaymentConfirmed] = useState(false);

  const searchParams = new URLSearchParams(window.location.search);
  const productId = searchParams.get('produto') || 'cuidar';
  
  const [product, setProduct] = useState(() => PRODUCTS[productId] || PRODUCTS['cuidar']);
  // T3.2: juros e bumps começam nos hardcoded (fallback) e são sobrescritos pelo /catalog.
  const [interestRates, setInterestRates] = useState<Record<number, number>>(INTEREST_RATES);
  const [bumps, setBumps] = useState(ORDER_BUMPS);

  useEffect(() => {
    if (productId.startsWith('custom_')) {
      fetchWithTimeout(`https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/custom-link?id=${productId}&t=${Date.now()}`, {}, 15000)
        .then(res => res.json())
        .then((data: CustomLinkResponse) => {
          if (data.sucesso) {
            // Status do link (controlado no PWA): off -> ajuda, espera -> vagas-esgotadas
            if (data.status === 'off') { window.location.replace('https://ajuda.reikitimeacademy.com.br'); return; }
            if (data.status === 'espera') { window.location.replace('https://vagas-esgotadas.reikitimeacademy.com.br'); return; }
            setProduct({
              title: data.title ?? '',
              subtitle: data.subtitle ?? '',
              brlPrice: data.brlPrice ?? 0,
              brlOriginal: data.brlPrice ?? 0,
              usdPrice: data.usdPrice ?? 0,
              eurPrice: data.eurPrice ?? 0,
              image: data.image ?? '',
              is_carne: data.is_carne,
              is_subscription: data.is_subscription,
              parcelas_carne: data.parcelas_carne
            });
            if (data.is_carne) {
              setCurrency('BRL');
              setPaymentMethod('boleto_parcelado' as PaymentMethod);
            } else if (data.is_subscription) {
              setPaymentMethod('credit_card' as PaymentMethod);
            }
          } else {
            window.location.href = 'https://ajuda.reikitimeacademy.com.br';
          }
        })
        .catch(err => {
          console.error("Falha ao carregar link customizado", err);
          window.location.href = 'https://ajuda.reikitimeacademy.com.br';
        });
    }
  }, [productId]);

  // T3.2: /catalog é a fonte única de preços/juros/bumps. Fallback aos hardcoded se falhar.
  useEffect(() => {
    if (productId.startsWith('custom_')) return; // custom usa os preços do próprio link
    fetchWithTimeout('https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/catalog', {}, 10000)
      .then(res => res.json())
      .then((data: CatalogResponse) => {
        if (data?.interest_rates) setInterestRates(prev => ({ ...prev, ...data.interest_rates }));
        if (data?.products?.[productId]) {
          const c = data.products[productId];
          setProduct(p => ({ ...p, brlPrice: c.preco_brl, usdPrice: c.preco_usd, eurPrice: c.preco_eur }));
        }
        if (data?.bumps) {
          const catBumps = data.bumps;
          setBumps(prev => prev.map(b => catBumps[b.id]
            ? { ...b, brlPrice: catBumps[b.id].brl, usdPrice: catBumps[b.id].usd, eurPrice: catBumps[b.id].eur }
            : b));
        }
      })
      .catch(() => {}); // mantém os hardcoded
  }, [productId]);

  // Ensure current currency is valid for the product
  useEffect(() => {
    if (currency === 'BRL' && product.brlPrice <= 0) {
      if (product.usdPrice > 0) setCurrency('USD');
      else if (product.eurPrice > 0) setCurrency('EUR');
    } else if (currency === 'USD' && product.usdPrice <= 0) {
      if (product.brlPrice > 0) setCurrency('BRL');
      else if (product.eurPrice > 0) setCurrency('EUR');
    } else if (currency === 'EUR' && product.eurPrice <= 0) {
      if (product.brlPrice > 0) setCurrency('BRL');
      else if (product.usdPrice > 0) setCurrency('USD');
    }
  }, [product, currency]);

  const getImmediateDeliveryText = () => {
    if (productId === 'cuidar') {
      return 'Compreendo que este é um treinamento com encontros ao vivo. Ao confirmar a compra, garanto minha vaga e reconheço as regras de participação e garantia aplicáveis.';
    } else if (productId === 'cer') {
      return 'Compreendo que receberei acesso imediato a mais de 300 aulas gravadas, além dos encontros quinzenais ao vivo. Por solicitar o acesso instantâneo ao conteúdo digital, reconheço que estou abrindo mão do direito de arrependimento (Art. 49 do CDC), não havendo reembolso após o acesso.';
    } else {
      return 'Compreendo que este é um produto digital de consumo imediato. Ao confirmar a compra, solicito acesso instantâneo ao material e reconheço que, por sua natureza digital, estou abrindo mão do direito de arrependimento de 7 dias (Art. 49 do CDC), não havendo reembolso.';
    }
  };
  
  const [couponCode, setCouponCode] = useState<string | null>(searchParams.get('cupom'));
  const [couponDiscount, setCouponDiscount] = useState<{ type: string, amount: number } | null>(null);

  useEffect(() => {
    if (couponCode) {
      // Usa a URL de produção para a API (EAD, onde o snippet backend-wpcode.php mora)
      fetchWithTimeout(`https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/coupon?code=${couponCode}`, {}, 12000)
        .then(res => res.json())
        .then((data: CouponResponse) => {
          if (data.sucesso) {
            setCouponDiscount({ type: data.tipo ?? '', amount: data.valor ?? 0 });
          } else {
            console.warn("Cupom inválido:", data.message);
            setCouponCode(null);
          }
        })
        .catch(err => {
            console.error("Erro ao validar cupom", err);
            setCouponCode(null);
        });
    }
  }, []);

  useEffect(() => {
    if (currency !== 'BRL') {
      const fetchIntent = async () => {
        setClientSecret('');
        try {
          const res = await fetchWithTimeout('https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/stripe-intent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              produto: productId,
              currency,
              bumps: selectedBumps,
              cupom: couponCode || ''
            })
          });
          const data: StripeIntentResponse = await res.json();
          if (data.client_secret) {
            setClientSecret(data.client_secret);
            setPaymentIntentId(data.payment_intent_id ?? '');
          }
        } catch (e) {
          console.error("Erro ao gerar intent", e);
        }
      };
      // Debounce para não chamar a cada tick
      const to = setTimeout(fetchIntent, 500);
      return () => clearTimeout(to);
    }
  }, [currency, productId, selectedBumps, couponCode]);

  // ── LEAD CAPTURE AUTOMÁTICO ──
  // Dispara o POST /lead em background quando o usuário preenche nome + email.
  // Usa useRef para garantir que só dispara UMA VEZ por sessão (não a cada keystroke).
  const leadSentRef = useRef<string>('');

  useEffect(() => {
    // Só dispara se: nome tem sobrenome e email tem @
    if (!nome.includes(' ') || !email.includes('@')) return;

    // Evita chamadas repetidas com os exatos mesmos dados
    const currentData = JSON.stringify({ nome, email, telefone });
    if (leadSentRef.current === currentData) return;

    // Debounce de 2 segundos — espera o usuário parar de digitar
    const timer = setTimeout(() => {
      leadSentRef.current = currentData; // Atualiza a ref com o último envio

      const formData = new URLSearchParams();
      formData.append("nome", nome);
      formData.append("email", email);
      formData.append("produto", productId);
      if (telefone) formData.append("telefone", telefone);

      // Fire-and-forget — não bloqueia nada, falha silenciosa
      fetchWithTimeout("https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/lead", {
        method: "POST",
        body: formData
      }, 10000).catch(() => {});
    }, 2000);

    return () => clearTimeout(timer);
  }, [nome, email, telefone]);
  
  const getProductPrice = () => {
    if (currency === 'USD') return product.usdPrice;
    if (currency === 'EUR') return product.eurPrice;
    return product.brlPrice;
  };
  
  const productPrice = getProductPrice();
  
  const basePrice = computeBasePrice({
    productPrice,
    productId,
    currency,
    bumps,
    selectedBumps,
    couponDiscount,
  });

  const total = computeTotal({
    basePrice,
    currency,
    paymentMethod,
    installments,
    installments2,
    rates: interestRates,
    card1EntryValue: Number(card1EntryValue) || 0,
    pixEntryValue: Number(pixEntryValue) || 0,
  });
  const installmentValue = currency === 'BRL' ? total / installments : total;

  const handleCheckout = async (e: React.FormEvent) => {
    e.preventDefault();
    setServerError(null);
    const newErrors: Record<string, string> = {};

    if (!agreedTerms) newErrors.agreedTerms = 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.';
    if (!agreedImmediateDelivery) newErrors.agreedImmediateDelivery = 'Você precisa confirmar as regras de acesso do treinamento escolhido.';

    if (!nome.includes(' ')) newErrors.nome = 'Digite seu nome completo';
    if (!email.includes('@')) newErrors.email = 'E-mail inválido';
    
    if (currency === 'BRL') {
      if (!isValidCPF(cpf)) newErrors.cpf = 'CPF inválido';
      if (!telefone || telefone.length < 10) newErrors.telefone = 'Celular inválido';
    }

    if (currency === 'BRL' && paymentMethod === 'credit_card') {
      if (!isValidLuhn(ccNumber)) newErrors.ccNumber = 'Número do cartão inválido';
      if (!ccName.includes(' ')) newErrors.ccName = 'Nome como impresso no cartão';
      if (ccExpiry.length < 5) newErrors.ccExpiry = 'Validade incompleta';
      if (ccCvv.length < 3) newErrors.ccCvv = 'CVV inválido';
      if (cep.replace(/\D/g, '').length !== 8) newErrors.cep = 'CEP inválido';
      if (!numero) newErrors.numero = 'Número obrigatório';
    }

    if (currency === 'BRL' && (paymentMethod === 'two_cards' || paymentMethod === 'pix_and_card')) {
      if (!isValidLuhn(ccNumber)) newErrors.ccNumber = 'Número do cartão inválido';
      if (!ccName.includes(' ')) newErrors.ccName = 'Nome incompleto';
      if (ccExpiry.length < 5) newErrors.ccExpiry = 'Validade incompleta';
      if (ccCvv.length < 3) newErrors.ccCvv = 'CVV inválido';
      if (cep.replace(/\D/g, '').length !== 8) newErrors.cep = 'CEP inválido';
      if (!numero) newErrors.numero = 'Número obrigatório';
      
      if (paymentMethod === 'two_cards') {
        if (!isValidLuhn(ccNumber2)) newErrors.ccNumber2 = 'Número do 2º cartão inválido';
        if (!ccName2.includes(' ')) newErrors.ccName2 = 'Nome 2º cartão incompleto';
        if (ccExpiry2.length < 5) newErrors.ccExpiry2 = 'Validade 2º cartão incompleta';
        if (ccCvv2.length < 3) newErrors.ccCvv2 = 'CVV 2º cartão inválido';
      }
    }

    if (currency === 'BRL' && (paymentMethod === 'pix_and_boleto' || paymentMethod === 'pix_and_card')) {
      const pixVal = Number(pixEntryValue) || 0;
      if (pixVal <= 0 || pixVal >= basePrice) {
        newErrors.pixEntryValue = 'A entrada no Pix deve ser maior que 0 e menor que o valor total.';
      }
    }

    if (currency === 'BRL' && paymentMethod === 'two_cards') {
      const c1Val = Number(card1EntryValue) || 0;
      if (c1Val <= 0 || c1Val >= basePrice) {
        newErrors.card1EntryValue = 'O valor no 1º cartão deve ser maior que 0 e menor que o valor total.';
      }
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    if (!turnstileToken) {
      setErrors({ turnstile: 'Verificação de segurança pendente. Aguarde ou recarregue a página.' });
      return;
    }

    setErrors({});
    setIsLoading(true);

    // 1. Preparar o Payload
    const payload: CheckoutPayload = {
      nome, email, telefone, cpf,
      produto: productId,
      valorTotal: total,
      gateway: currency === 'BRL' ? 'asaas' : 'stripe',
      currency: currency,
      parcelas: installments,
      parcelas_carne: product.is_carne ? product.parcelas_carne : undefined,
      metodo: paymentMethod,
      bumps: selectedBumps,
      turnstileToken: turnstileToken,
      cupom: couponCode || ''
    };

    // 2. Se for Stripe, usamos o novo fluxo
    if (currency !== 'BRL') {
      if (!stripeRef.current) {
        setServerError('O sistema de cartão não carregou corretamente. Recarregue a página.');
        setIsLoading(false);
        return;
      }
      
      const { error: submitError, stripe, elements } = await stripeRef.current.confirm();
      if (submitError) {
        setServerError(submitError.message || 'Verifique os dados informados.');
        setIsLoading(false);
        return;
      }
      if (!stripe || !elements) {
        setServerError('O sistema de cartão não carregou corretamente. Recarregue a página.');
        setIsLoading(false);
        return;
      }

      // Assinatura internacional: /stripe-intent devolve um SetupIntent (seti_).
      // Confirmamos o SetupIntent ANTES de o backend criar a assinatura.
      if (paymentIntentId.startsWith('seti_')) {
        const setupRes = await stripe.confirmSetup({
          elements,
          clientSecret,
          confirmParams: { payment_method_data: { billing_details: { name: nome, email } } },
          redirect: 'if_required',
        });
        if (setupRes.error) {
          turnstileRef.current?.reset();
          setTurnstileToken(null);
          setServerError(setupRes.error.message || 'Erro ao validar o cartão.');
          setIsLoading(false);
          return;
        }
        payload.gateway = 'stripe_update';
        payload.payment_intent_id = setupRes.setupIntent?.id || paymentIntentId;
        try {
          const subRes = await fetchWithTimeout('https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          });
          const subData: CheckoutResponse = await subRes.json();
          if (!subRes.ok) {
            turnstileRef.current?.reset();
            setTurnstileToken(null);
            setServerError(subData.message || 'Erro ao criar a assinatura. Tente novamente.');
            setIsLoading(false);
            return;
          }
          setIsSuccess(true);
        } catch (err) {
          turnstileRef.current?.reset();
          setTurnstileToken(null);
          setServerError(err instanceof Error && err.name === 'AbortError'
            ? 'O servidor demorou a responder. Verifique seu e-mail antes de tentar de novo.'
            : 'Falha de conexão com nossos servidores.');
        }
        setIsLoading(false);
        return;
      }

      payload.gateway = 'stripe_update';
      payload.payment_intent_id = paymentIntentId;

      try {
        const wpRes = await fetchWithTimeout("https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/checkout", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        const wpData: CheckoutResponse = await wpRes.json();
        
        if (!wpRes.ok) {
          turnstileRef.current?.reset();
          setTurnstileToken(null);
          setServerError(wpData.message || 'Erro ao processar pedido. Tente novamente.');
          setIsLoading(false);
          return;
        }

        const confirmRes = await stripe.confirmPayment({
          elements,
          clientSecret,
          confirmParams: {
            payment_method_data: {
              billing_details: { name: nome, email: email }
            }
          },
          redirect: 'if_required'
        });

        if (confirmRes.error) {
          turnstileRef.current?.reset();
          setTurnstileToken(null);
          setServerError(confirmRes.error.message || 'Erro ao processar pagamento na Stripe.');
          setIsLoading(false);
          return;
        }
        
        setIsSuccess(true);
      } catch (err) {
        turnstileRef.current?.reset();
        setTurnstileToken(null);
        setServerError(err instanceof Error && err.name === 'AbortError'
          ? 'O servidor demorou a responder. Seu pagamento pode não ter sido processado — aguarde alguns instantes e verifique seu e-mail antes de tentar de novo.'
          : 'Falha de conexão com nossos servidores.');
      }
      setIsLoading(false);
      return;
    } 
    // 3. Se for Asaas Cartão, pegamos os dados diretos
    else if (paymentMethod === 'credit_card') {
        payload.cc_name = ccName;
        payload.cc_number = ccNumber;
        payload.cc_expiry = ccExpiry;
        payload.cc_cvv = ccCvv;
        payload.cep = cep.replace(/\D/g, '');
        payload.numero = numero;
    } else if (paymentMethod === 'two_cards') {
        const value1 = Number(card1EntryValue) || 0;
        const value2 = Math.max(0, basePrice - value1); // base sem juros; o backend aplica os juros por cartão
        
        payload.cards = [
          {
            value: value1,
            cc_name: ccName,
            cc_number: ccNumber,
            cc_expiry: ccExpiry,
            cc_cvv: ccCvv,
            parcelas: installments
          },
          {
            value: value2,
            cc_name: ccName2,
            cc_number: ccNumber2,
            cc_expiry: ccExpiry2,
            cc_cvv: ccCvv2,
            parcelas: installments2
          }
        ];
        payload.cep = cep.replace(/\D/g, '');
        payload.numero = numero;
    } else if (paymentMethod === 'pix_and_card') {
        const pixVal = Number(pixEntryValue) || 0;
        const cardVal = Math.max(0, basePrice - pixVal); // base sem juros; o backend aplica os juros no cartão

        payload.pix_value = pixVal;
        payload.card = {
            value: cardVal,
            cc_name: ccName,
            cc_number: ccNumber,
            cc_expiry: ccExpiry,
            cc_cvv: ccCvv,
            parcelas: installments
        };
        payload.cep = cep.replace(/\D/g, '');
        payload.numero = numero;
    } else if (paymentMethod === 'pix_and_boleto') {
        const pixVal = Number(pixEntryValue) || 0;
        payload.pix_value = pixVal;
        payload.cep = cep.replace(/\D/g, '');
        payload.numero = numero;
    } else if (paymentMethod === 'boleto' || paymentMethod === 'boleto_parcelado') {
        payload.cep = cep.replace(/\D/g, '');
        payload.numero = numero;
    }

    // 4. Enviar para o WordPress (EAD)
    try {
      const response = await fetchWithTimeout('https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data: CheckoutResponse = await response.json();

      if (!response.ok) {
        turnstileRef.current?.reset();
        setTurnstileToken(null);
        setServerError(data.message || 'Erro no processamento da compra.');
      } else {
        setIsSuccess(true);
        if (data.pix_qrcode) {
          setPixData({ qrcode: data.pix_qrcode, copia_cola: data.pix_copia_cola ?? '' });
        }
        if (data.boleto_url) {
          setBoletoUrl(data.boleto_url);
        }
        if (data.payment_id) {
          setPaymentId(data.payment_id);
        }
      }
    } catch (err) {
      turnstileRef.current?.reset();
      setTurnstileToken(null);
      setServerError(err instanceof Error && err.name === 'AbortError'
        ? 'O servidor demorou a responder. Aguarde alguns instantes e verifique seu e-mail antes de tentar novamente.'
        : 'Falha de conexão com o servidor. Verifique sua internet.');
    } finally {
      setIsLoading(false);
    }
  };

  // T4.3: enquanto há PIX/boleto pendente, consulta o status; quando pago, a tela atualiza sozinha.
  useEffect(() => {
    if (!isSuccess || !paymentId || paymentConfirmed) return;
    if (!pixData && !boletoUrl) return;
    let active = true;
    const startedAt = Date.now();
    const poll = async () => {
      if (!active || Date.now() - startedAt > 20 * 60 * 1000) return; // para após 20 min
      try {
        const res = await fetchWithTimeout(
          `https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/payment-status?payment_id=${encodeURIComponent(paymentId)}`,
          {}, 12000
        );
        const data: PaymentStatusResponse = await res.json();
        if (active && data?.paid) { setPaymentConfirmed(true); return; }
      } catch { /* ignora e tenta de novo */ }
      if (active) setTimeout(poll, 8000);
    };
    const t = setTimeout(poll, 8000);
    return () => { active = false; clearTimeout(t); };
  }, [isSuccess, paymentId, pixData, boletoUrl, paymentConfirmed]);

  // Tela de Sucesso
  if (isSuccess) {
    return <SuccessScreen pixData={pixData} boletoUrl={boletoUrl} paymentId={paymentId} paymentConfirmed={paymentConfirmed} />;
  }

  return (
    <div className="min-h-screen bg-stone-50 font-sans text-stone-800 pb-12">
      <header className="bg-white border-b border-stone-200 py-4 px-6 md:px-12 flex items-center justify-between sticky top-0 z-10">
        <div className="font-bold text-xl tracking-tight text-emerald-800">Reiki Time Academy</div>
        
        <div className="flex bg-stone-100 p-1 rounded-lg border border-stone-200">
          {product.brlPrice > 0 && (
            <button type="button" onClick={() => { setCurrency('BRL'); setPaymentMethod('credit_card'); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'BRL' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
              🇧🇷 BRL
            </button>
          )}
          {product.usdPrice > 0 && (
            <button type="button" onClick={() => { setCurrency('USD'); setPaymentMethod('credit_card'); setInstallments(1); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'USD' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
              🇺🇸 USD
            </button>
          )}
          {product.eurPrice > 0 && (
            <button type="button" onClick={() => { setCurrency('EUR'); setPaymentMethod('credit_card'); setInstallments(1); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'EUR' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
              🇪🇺 EUR
            </button>
          )}
        </div>
      </header>

      {serverError && (
        <div className="max-w-6xl mx-auto mt-6 px-4 md:px-8">
          <div className="bg-red-50 text-red-700 border border-red-200 p-4 rounded-xl flex items-center justify-between">
            <span className="font-medium">{serverError}</span>
            <button onClick={() => setServerError(null)} className="text-red-500 hover:text-red-800 text-xl font-bold">&times;</button>
          </div>
        </div>
      )}

      <form onSubmit={handleCheckout} className="max-w-6xl mx-auto py-8 px-4 md:px-8 grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12">
        {/* ... (Todo o restante do formulário é idêntico) ... */}
        <div className="lg:col-span-7 space-y-8">
          
          <section className="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-stone-200">
            <h2 className="text-xl font-bold mb-6 flex items-center gap-2">
              <span className="bg-emerald-100 text-emerald-700 w-6 h-6 flex items-center justify-center rounded-full text-sm">1</span>
              Dados Pessoais
            </h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-stone-600 mb-1">Nome Completo</label>
                <input value={nome} onChange={e=>setNome(e.target.value)} type="text" className={`w-full border ${errors.nome ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="Digite seu nome completo" />
                {errors.nome && <p className="text-red-500 text-xs mt-1">{errors.nome}</p>}
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-stone-600 mb-1">E-mail</label>
                  <input value={email} onChange={e=>setEmail(e.target.value)} type="email" className={`w-full border ${errors.email ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="seu@email.com" />
                  {errors.email && <p className="text-red-500 text-xs mt-1">{errors.email}</p>}
                </div>
                {currency === 'BRL' && (
                  <div>
                    <label className="block text-sm font-medium text-stone-600 mb-1">Celular / WhatsApp</label>
                    <input value={telefone} onChange={e=>setTelefone(e.target.value)} type="tel" className={`w-full border ${errors.telefone ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="(00) 00000-0000" />
                    {errors.telefone && <p className="text-red-500 text-xs mt-1">{errors.telefone}</p>}
                  </div>
                )}
              </div>
              
              {currency === 'BRL' && (
                <div>
                  <label className="block text-sm font-medium text-stone-600 mb-1">CPF</label>
                  <input type="text" value={cpf} onChange={e => {
                    let v = e.target.value.replace(/\D/g, '');
                    if (v.length > 11) v = v.slice(0,11);
                    v = v.replace(/(\d{3})(\d)/, '$1.$2');
                    v = v.replace(/(\d{3})(\d)/, '$1.$2');
                    v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                    setCpf(v);
                    setErrors(prev => ({...prev, cpf: ''}));
                  }} className={`w-full p-3 bg-stone-50 border rounded-lg focus:ring-2 outline-none transition-all ${errors.cpf ? 'border-red-500 focus:ring-red-200' : 'border-stone-200 focus:border-emerald-500 focus:ring-emerald-100'}`} placeholder="000.000.000-00" />
                  {errors.cpf && <p className="text-red-500 text-xs mt-1">{errors.cpf}</p>}
                </div>
              )}

              {currency === 'BRL' && ['credit_card', 'two_cards', 'pix_and_card', 'boleto_parcelado'].includes(paymentMethod) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                  <div>
                    <label className="block text-sm font-medium text-stone-700 mb-1">CEP *</label>
                    <input type="text" value={cep} onChange={e => {
                      let v = e.target.value.replace(/\D/g, '');
                      if (v.length > 8) v = v.slice(0, 8);
                      v = v.replace(/^(\d{5})(\d)/, '$1-$2');
                      setCep(v);
                      setErrors(prev => ({...prev, cep: ''}));
                    }} className={`w-full p-3 bg-stone-50 border rounded-lg focus:ring-2 outline-none transition-all ${errors.cep ? 'border-red-500 focus:ring-red-200' : 'border-stone-200 focus:border-emerald-500 focus:ring-emerald-100'}`} placeholder="00000-000" />
                    {errors.cep && <p className="text-red-500 text-xs mt-1">{errors.cep}</p>}
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-stone-700 mb-1">Número *</label>
                    <input type="text" value={numero} onChange={e => {
                      setNumero(e.target.value);
                      setErrors(prev => ({...prev, numero: ''}));
                    }} className={`w-full p-3 bg-stone-50 border rounded-lg focus:ring-2 outline-none transition-all ${errors.numero ? 'border-red-500 focus:ring-red-200' : 'border-stone-200 focus:border-emerald-500 focus:ring-emerald-100'}`} placeholder="123" />
                    {errors.numero && <p className="text-red-500 text-xs mt-1">{errors.numero}</p>}
                  </div>
                  {endereco && (
                    <div className="md:col-span-2">
                      <p className="text-sm text-stone-600 bg-stone-100 p-2 rounded-lg border border-stone-200">{endereco}</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          </section>

          <section className="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-stone-200">
            <h2 className="text-xl font-bold mb-6 flex items-center gap-2">
              <span className="bg-emerald-100 text-emerald-700 w-6 h-6 flex items-center justify-center rounded-full text-sm">2</span>
              Pagamento {currency !== 'BRL' && <span className="ml-2 text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-bold">Via Stripe</span>}
            </h2>
            
            {currency === 'BRL' && !product.is_carne && !product.is_subscription && (
              <div className="grid grid-cols-2 md:grid-cols-6 gap-2 md:gap-2 mb-6">
                <button type="button" onClick={() => { setPaymentMethod('credit_card'); setInstallments(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'credit_card' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <CreditCard className="w-5 h-5" />
                  <span className="text-xs font-medium">Cartão</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('pix'); setInstallments(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'pix' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <QrCode className="w-5 h-5" />
                  <span className="text-xs font-medium">Pix</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('two_cards'); setInstallments(1); setInstallments2(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'two_cards' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <div className="flex -space-x-2"><CreditCard className="w-5 h-5" /><CreditCard className="w-5 h-5 opacity-50" /></div>
                  <span className="text-[10px] md:text-xs font-medium text-center leading-tight">2 Cartões</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('pix_and_card'); setInstallments(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'pix_and_card' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <div className="flex -space-x-1"><QrCode className="w-5 h-5" /><CreditCard className="w-5 h-5 opacity-50" /></div>
                  <span className="text-[10px] md:text-xs font-medium text-center leading-tight">Pix + Cartão</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('boleto'); setInstallments(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'boleto' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <Receipt className="w-5 h-5" />
                  <span className="text-xs font-medium text-center leading-tight">Boleto</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('pix_and_boleto'); setInstallments(1); }} className={`p-2 border rounded-xl flex flex-col items-center justify-center gap-1 transition-all ${paymentMethod === 'pix_and_boleto' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <div className="flex -space-x-1"><QrCode className="w-5 h-5" /><Receipt className="w-5 h-5 opacity-50" /></div>
                  <span className="text-[10px] md:text-xs font-medium text-center leading-tight">Pix + Boleto</span>
                </button>
              </div>
            )}
            
            {currency === 'BRL' && product.is_carne && (
              <div className="grid grid-cols-1 mb-6">
                <button type="button" className={`p-4 border rounded-xl flex flex-col items-center justify-center gap-2 transition-all border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500`}>
                  <Receipt className="w-8 h-8" />
                  <span className="font-bold text-lg text-center">Carnê Inteligente</span>
                  <span className="text-sm font-medium text-center text-emerald-600">{product.parcelas_carne}x no boleto</span>
                </button>
              </div>
            )}

            {currency === 'BRL' && (paymentMethod === 'credit_card' || paymentMethod === 'two_cards' || paymentMethod === 'pix_and_card' || paymentMethod === 'pix_and_boleto') && (
              <div className="space-y-6 animate-in fade-in">
                
                {(paymentMethod === 'pix_and_card' || paymentMethod === 'pix_and_boleto') && (
                  <div className="bg-emerald-50 border border-emerald-200 p-4 rounded-xl">
                    <h3 className="font-bold text-emerald-800 mb-3 flex items-center gap-2"><QrCode className="w-5 h-5"/> Valor no Pix</h3>
                    <div>
                      <label className="block text-sm font-medium text-emerald-700 mb-1">Quanto deseja pagar no Pix?</label>
                      <input value={pixEntryValue} onChange={e => {
                         let val = e.target.value.replace(/[^0-9.]/g, '');
                         setPixEntryValue(val);
                      }} type="text" className={`w-full border ${errors.pixEntryValue ? 'border-red-500' : 'border-emerald-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder={`Ex: ${Math.floor(basePrice/2)}`} />
                      {errors.pixEntryValue && <p className="text-red-500 text-xs mt-1">{errors.pixEntryValue}</p>}
                      <p className="text-xs text-emerald-600 mt-2">
                        O restante {pixEntryValue && !isNaN(Number(pixEntryValue)) ? `(R$ ${Math.max(0, basePrice - Number(pixEntryValue)).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})}) ` : ''} 
                        será cobrado no {paymentMethod === 'pix_and_card' ? 'cartão de crédito abaixo' : 'boleto à vista'}.
                      </p>
                    </div>
                  </div>
                )}

                {paymentMethod === 'two_cards' && (
                  <div className="bg-stone-50 border border-stone-200 p-4 rounded-xl">
                    <h3 className="font-bold text-stone-800 mb-3 flex items-center gap-2"><CreditCard className="w-5 h-5"/> Divisão de Valores</h3>
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">Quanto cobrar no 1º Cartão?</label>
                      <input value={card1EntryValue} onChange={e => {
                         let val = e.target.value.replace(/[^0-9.]/g, '');
                         setCard1EntryValue(val);
                      }} type="text" className={`w-full border ${errors.card1EntryValue ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-stone-500 outline-none`} placeholder={`Ex: ${Math.floor(basePrice/2)}`} />
                      {errors.card1EntryValue && <p className="text-red-500 text-xs mt-1">{errors.card1EntryValue}</p>}
                      <p className="text-xs text-stone-500 mt-2">O restante será cobrado no 2º cartão automaticamente.</p>
                    </div>
                  </div>
                )}

                {(paymentMethod === 'credit_card' || paymentMethod === 'two_cards' || paymentMethod === 'pix_and_card') && (
                  <div className="bg-white border border-stone-200 p-4 rounded-xl space-y-4">
                    <h3 className="font-bold text-stone-800 flex items-center gap-2">
                      <CreditCard className="w-5 h-5"/> 
                      {paymentMethod === 'two_cards' ? 'Dados do 1º Cartão' : 'Dados do Cartão'}
                    </h3>
                  <div>
                    <label className="block text-sm font-medium text-stone-600 mb-1">Número do Cartão</label>
                    <input value={ccNumber} onChange={e=>setCcNumber(e.target.value)} type="text" className={`w-full border ${errors.ccNumber ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="0000 0000 0000 0000" />
                    {errors.ccNumber && <p className="text-red-500 text-xs mt-1">{errors.ccNumber}</p>}
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">Validade</label>
                      <input value={ccExpiry} onChange={e=>setCcExpiry(e.target.value)} type="text" className={`w-full border ${errors.ccExpiry ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="MM/AA" />
                      {errors.ccExpiry && <p className="text-red-500 text-xs mt-1">{errors.ccExpiry}</p>}
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">CVV</label>
                      <input value={ccCvv} onChange={e=>setCcCvv(e.target.value)} type="text" className={`w-full border ${errors.ccCvv ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="123" />
                      {errors.ccCvv && <p className="text-red-500 text-xs mt-1">{errors.ccCvv}</p>}
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-stone-600 mb-1">Nome impresso no cartão</label>
                    <input value={ccName} onChange={e=>setCcName(e.target.value)} type="text" className={`w-full border ${errors.ccName ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="Ex: JOAO DA SILVA" />
                    {errors.ccName && <p className="text-red-500 text-xs mt-1">{errors.ccName}</p>}
                  </div>
                  
                  <div>
                    <label className="block text-sm font-medium text-stone-600 mb-1">Parcelamento</label>
                    {product.is_subscription ? (
                      <div className="w-full border border-stone-300 rounded-lg p-3 bg-stone-50 text-stone-700">
                        Pagamento mensal automático
                      </div>
                    ) : (
                      <select value={installments} onChange={(e) => setInstallments(Number(e.target.value))} className="w-full border border-stone-300 rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none bg-white">
                        {[1,2,3,4,5,6,7,8,9,10,11,12].map(num => {
                          const rate = interestRates[num] || 0;
                          let valToInstallment = basePrice;
                          if (paymentMethod === 'two_cards') valToInstallment = Number(card1EntryValue) || 0;
                          if (paymentMethod === 'pix_and_card') valToInstallment = Math.max(0, basePrice - (Number(pixEntryValue) || 0));
                          
                          const instTotal = valToInstallment * (1 + rate / 100);
                          const instValue = instTotal / num;
                          return (
                            <option key={num} value={num}>
                              {num}x de {instValue.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                            </option>
                          )
                        })}
                      </select>
                    )}
                  </div>
                  </div>
                )}

                {paymentMethod === 'two_cards' && (
                  <div className="bg-white border border-stone-200 p-4 rounded-xl space-y-4">
                    <h3 className="font-bold text-stone-800 flex items-center gap-2"><CreditCard className="w-5 h-5"/> Dados do 2º Cartão</h3>
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">Número do Cartão</label>
                      <input value={ccNumber2} onChange={e=>setCcNumber2(e.target.value)} type="text" className={`w-full border ${errors.ccNumber2 ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="0000 0000 0000 0000" />
                      {errors.ccNumber2 && <p className="text-red-500 text-xs mt-1">{errors.ccNumber2}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-stone-600 mb-1">Validade</label>
                        <input value={ccExpiry2} onChange={e=>setCcExpiry2(e.target.value)} type="text" className={`w-full border ${errors.ccExpiry2 ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="MM/AA" />
                        {errors.ccExpiry2 && <p className="text-red-500 text-xs mt-1">{errors.ccExpiry2}</p>}
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-stone-600 mb-1">CVV</label>
                        <input value={ccCvv2} onChange={e=>setCcCvv2(e.target.value)} type="text" className={`w-full border ${errors.ccCvv2 ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="123" />
                        {errors.ccCvv2 && <p className="text-red-500 text-xs mt-1">{errors.ccCvv2}</p>}
                      </div>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">Nome impresso no cartão</label>
                      <input value={ccName2} onChange={e=>setCcName2(e.target.value)} type="text" className={`w-full border ${errors.ccName2 ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="Ex: JOAO DA SILVA" />
                      {errors.ccName2 && <p className="text-red-500 text-xs mt-1">{errors.ccName2}</p>}
                    </div>
                    
                    <div>
                      <label className="block text-sm font-medium text-stone-600 mb-1">Parcelamento (2º Cartão)</label>
                      <select value={installments2} onChange={(e) => setInstallments2(Number(e.target.value))} className="w-full border border-stone-300 rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none bg-white">
                        {[1,2,3,4,5,6,7,8,9,10,11,12].map(num => {
                          const rate = interestRates[num] || 0;
                          const valToInstallment = Math.max(0, basePrice - (Number(card1EntryValue) || 0));
                          const instTotal = valToInstallment * (1 + rate / 100);
                          const instValue = instTotal / num;
                          return (
                            <option key={num} value={num}>
                              {num}x de {instValue.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                            </option>
                          )
                        })}
                      </select>
                    </div>
                  </div>
                )}
              </div>
            )}
            
            {currency === 'BRL' && paymentMethod === 'pix' && (
              <div className="bg-emerald-50 border border-emerald-100 rounded-xl p-6 text-center animate-in fade-in">
                <QrCode className="w-12 h-12 text-emerald-600 mx-auto mb-3" />
                <h3 className="font-bold text-emerald-900 mb-2">Pagamento rápido e seguro</h3>
                <p className="text-emerald-700 text-sm">Ao finalizar a compra, você receberá o código Pix Copia e Cola. A liberação do curso é imediata!</p>
              </div>
            )}

            {currency === 'BRL' && paymentMethod === 'boleto' && (
              <div className="bg-stone-50 border border-stone-200 rounded-xl p-6 text-center animate-in fade-in">
                <Receipt className="w-12 h-12 text-stone-400 mx-auto mb-3" />
                <h3 className="font-bold text-stone-700 mb-2">Boleto Bancário à vista</h3>
                <p className="text-stone-500 text-sm">A compensação pode levar até 2 dias úteis para liberação do seu acesso.</p>
              </div>
            )}

            {currency === 'BRL' && paymentMethod === 'boleto_parcelado' && (
              <div className="bg-emerald-50 border border-emerald-200 rounded-xl p-6 text-center animate-in fade-in">
                <Receipt className="w-12 h-12 text-emerald-500 mx-auto mb-3" />
                <h3 className="font-bold text-emerald-900 mb-2">Carnê Inteligente ({product.parcelas_carne}x)</h3>
                <p className="text-emerald-700 text-sm">Você pagará a primeira parcela hoje e receberá o acesso imediatamente após a compensação. As demais parcelas serão enviadas mensalmente.</p>
              </div>
            )}

            {currency !== 'BRL' && (
              <div className="space-y-4 animate-in fade-in">
                <div className="bg-stone-50 border border-stone-200 rounded-lg p-4">
                  <label className="block text-sm font-medium text-stone-600 mb-2">Dados do Cartão (Protegido por Stripe)</label>
                  <div className="p-3 bg-white border border-stone-300 rounded-md">
                    <StripeWrapper ref={stripeRef} clientSecret={clientSecret} />
                  </div>
                </div>
                <p className="text-xs text-stone-500 text-center"><Lock className="w-3 h-3 inline" /> Seus dados não passam por nossos servidores.</p>
              </div>
            )}
          </section>

        </div>

        <div className="lg:col-span-5">
          <div className="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-stone-200 sticky top-24">
            <h3 className="font-bold text-lg mb-6 border-b pb-4 flex justify-between items-center">
              Resumo da Compra
              <Globe className="w-5 h-5 text-stone-400" />
            </h3>
            
            <div className="flex gap-4 mb-6">
              <div className="w-20 h-20 rounded-lg border border-stone-200 flex-shrink-0 overflow-hidden bg-stone-100">
                <img src={product.image} alt={product.title} className="w-full h-full object-cover" />
              </div>
              <div>
                <h4 className="font-semibold text-stone-800 leading-tight">{product.title}</h4>
                <p className="text-sm text-stone-500 mt-1">{product.subtitle}</p>
              </div>
            </div>

            <div className="space-y-3 mb-6">
              <div className="flex justify-between text-sm text-stone-600">
                <span>{product.is_subscription && (product.parcelas_carne || 1) > 1 ? `Plano de ${product.parcelas_carne} meses` : 'Valor com desconto'}</span>
                <span className={couponDiscount ? 'line-through text-stone-400' : ''}>
                  {product.is_subscription && (product.parcelas_carne || 1) > 1 ? (
                    <>
                      {product.parcelas_carne}x de {currency === 'BRL' ? (productPrice/(product.parcelas_carne||1)).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : currency === 'USD' ? (productPrice/(product.parcelas_carne||1)).toLocaleString('en-US', { style: 'currency', currency: 'USD' }) : (productPrice/(product.parcelas_carne||1)).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })} / mês
                    </>
                  ) : (
                    <>
                      {currency === 'BRL' && productPrice.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                      {currency === 'USD' && productPrice.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                      {currency === 'EUR' && productPrice.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                    </>
                  )}
                </span>
              </div>
              {couponDiscount && couponCode && (
                <div className="flex justify-between text-sm text-emerald-600 font-medium animate-in fade-in slide-in-from-top-2">
                  <span>🎟️ Cupom aplicado ({couponCode})</span>
                  <span>
                    {couponDiscount.type === 'percent' 
                      ? `- ${couponDiscount.amount}%` 
                      : (currency === 'BRL' ? `- ${couponDiscount.amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}` : '')}
                  </span>
                </div>
              )}
              {currency === 'BRL' && paymentMethod === 'credit_card' && installments > 1 && (
                <div className="flex justify-between text-sm text-amber-600">
                  <span>Juros de parcelamento ({installments}x)</span>
                  <span>+ {(total - productPrice).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
                </div>
              )}
            </div>

            {productId === 'infinity' && (
              <div className="space-y-4 mb-6">
                <div className="text-sm font-bold text-stone-800 border-b pb-2">Complete seu pedido:</div>
                {bumps.map(bump => {
                  const isSelected = selectedBumps.includes(bump.id);
                  return (
                    <div 
                      key={bump.id} 
                      className={`border-2 rounded-xl overflow-hidden transition-all duration-300 bg-stone-50 ${isSelected ? 'border-red-500 bg-red-50/30' : 'border-stone-200'}`}
                    >
                      {/* Header do Bump */}
                      <div className={`text-center py-1.5 text-[11px] font-bold tracking-wider uppercase flex items-center justify-center gap-1.5 border-b ${isSelected ? 'bg-red-500 text-white border-red-600' : 'bg-red-50 text-red-600 border-red-100'}`}>
                        <span className={isSelected ? "text-white" : "text-red-500"}>🔥</span>
                        Oferta Exclusiva — Apenas uma vez
                        <span className={isSelected ? "text-white" : "text-red-500"}>🔥</span>
                      </div>
                      
                      {/* Corpo do Bump */}
                      <div className="p-4">
                        <label className="flex items-start gap-3 cursor-pointer group">
                          <div className="relative flex items-center justify-center pt-0.5">
                            <input 
                              type="checkbox" 
                              checked={isSelected}
                              onChange={() => toggleBump(bump.id)}
                              className="sr-only"
                            />
                            <div className={`w-5 h-5 border-2 rounded transition-all duration-300 flex items-center justify-center ${isSelected ? 'bg-red-500 border-red-500' : 'border-stone-400 bg-white group-hover:border-red-400'}`}>
                              {isSelected && <CheckCircle2 className="w-3.5 h-3.5 text-white" />}
                            </div>
                          </div>
                          
                          <div className="flex-1">
                            <h4 className={`text-sm font-bold leading-tight transition-colors ${isSelected ? 'text-stone-900' : 'text-stone-800'}`}>
                              {bump.title}
                            </h4>
                          </div>
                        </label>

                        {isSelected && (
                          <div className="mt-3 pl-8 animate-in slide-in-from-top-2 fade-in duration-300">
                            <p className="text-stone-600 text-[13px] leading-snug mb-3">
                              {bump.desc}
                            </p>
                          </div>
                        )}
                        <div className="mt-2 pl-8">
                            <div className="bg-green-50 rounded px-2 py-1.5 border border-green-100 inline-block">
                              <p className="text-green-700 font-bold text-xs">
                                {bump.brlOriginal > 0 && bump.id !== '12895' && (
                                  <>De {currency === 'BRL' && bump.brlOriginal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                                  {currency === 'USD' && bump.usdOriginal.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                                  {currency === 'EUR' && bump.eurOriginal.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })} — </>
                                )}
                                {bump.id === '12895' && "Adicionar Deusa AI PRO — 10 Créditos por apenas "}
                                {bump.brlOriginal > 0 && bump.id !== '12895' && "por apenas "}
                                {currency === 'BRL' && bump.brlPrice.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                                {currency === 'USD' && bump.usdPrice.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                                {currency === 'EUR' && bump.eurPrice.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                              </p>
                            </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            <div className="border-t pt-4 mb-8">
              <div className="flex justify-between items-end">
                <span className="font-medium text-stone-600">Total</span>
                <div className="text-right">
                  <span className="block text-2xl font-bold text-stone-800 flex flex-col items-end gap-1">
                    {product.is_subscription && (product.parcelas_carne || 1) > 1 ? (
                      <>
                        <span className="text-xl">
                          {product.parcelas_carne}x de {currency === 'BRL' ? (total/(product.parcelas_carne||1)).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : currency === 'USD' ? (total/(product.parcelas_carne||1)).toLocaleString('en-US', { style: 'currency', currency: 'USD' }) : (total/(product.parcelas_carne||1)).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })} / mês
                        </span>
                        <span className="text-sm font-normal text-stone-500">
                          Total: {currency === 'BRL' ? total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : currency === 'USD' ? total.toLocaleString('en-US', { style: 'currency', currency: 'USD' }) : total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                        </span>
                      </>
                    ) : (
                      <>
                        {currency === 'BRL' && total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                        {currency === 'USD' && total.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                        {currency === 'EUR' && total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                      </>
                    )}
                  </span>
                  {currency === 'BRL' && paymentMethod === 'credit_card' && installments > 1 && !product.is_subscription && (
                    <span className="text-sm text-emerald-600 font-medium">
                      ou {installments}x de {installmentValue.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                    </span>
                  )}
                </div>
              </div>
            </div>

            <div className="space-y-4 mb-8 bg-stone-50 p-4 rounded-xl border border-stone-200">
              <label className="flex items-start gap-3 cursor-pointer group">
                <div className="relative flex items-center justify-center pt-0.5">
                  <input type="checkbox" checked={agreedImmediateDelivery} onChange={(e) => setAgreedImmediateDelivery(e.target.checked)} className="sr-only" />
                  <div className={`w-5 h-5 border-2 rounded transition-all flex items-center justify-center ${agreedImmediateDelivery ? 'bg-emerald-500 border-emerald-500' : 'border-stone-400 bg-white group-hover:border-emerald-400'}`}>
                    {agreedImmediateDelivery && <CheckCircle2 className="w-3.5 h-3.5 text-white" />}
                  </div>
                </div>
                <div className="flex-1">
                  <p className={`text-xs leading-tight transition-colors ${errors.agreedImmediateDelivery ? 'text-red-600' : 'text-stone-700'}`}>
                    {getImmediateDeliveryText()}
                  </p>
                  {errors.agreedImmediateDelivery && <p className="text-red-500 text-xs mt-1 font-medium">{errors.agreedImmediateDelivery}</p>}
                </div>
              </label>

              <label className="flex items-start gap-3 cursor-pointer group">
                <div className="relative flex items-center justify-center pt-0.5">
                  <input type="checkbox" checked={agreedTerms} onChange={(e) => setAgreedTerms(e.target.checked)} className="sr-only" />
                  <div className={`w-5 h-5 border-2 rounded transition-all flex items-center justify-center ${agreedTerms ? 'bg-emerald-500 border-emerald-500' : 'border-stone-400 bg-white group-hover:border-emerald-400'}`}>
                    {agreedTerms && <CheckCircle2 className="w-3.5 h-3.5 text-white" />}
                  </div>
                </div>
                <div className="flex-1">
                  <p className={`text-xs leading-tight transition-colors ${errors.agreedTerms ? 'text-red-600' : 'text-stone-700'}`}>
                    Li e concordo com os <a href="https://cuidar.reikitimeacademy.com.br/termos-de-uso/" target="_blank" rel="noopener noreferrer" className="text-emerald-600 hover:underline font-medium">Termos de Uso</a> e <a href="https://cuidar.reikitimeacademy.com.br/politica-de-privacidade/" target="_blank" rel="noopener noreferrer" className="text-emerald-600 hover:underline font-medium">Política de Privacidade</a>.
                  </p>
                  {errors.agreedTerms && <p className="text-red-500 text-xs mt-1 font-medium">{errors.agreedTerms}</p>}
                </div>
              </label>
            </div>

            <button disabled={isLoading || !turnstileToken} type="submit" className={`w-full text-white font-bold py-4 rounded-xl shadow-lg transition-all text-lg flex items-center justify-center gap-2 disabled:opacity-50 ${currency === 'BRL' ? 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-200' : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200'}`}>
              {isLoading ? <><Loader2 className="w-5 h-5 animate-spin" /> Processando...</> : <><ShieldCheck className="w-5 h-5" /> Finalizar Compra {currency !== 'BRL' ? 'Internacional' : 'Segura'}</>}
            </button>
            {errors.turnstile && <p className="text-red-500 text-xs mt-2 text-center">{errors.turnstile}</p>}
            
            {/* TEXTOS LEGAIS DINÂMICOS */}
            {productId !== 'cuidar' && productId !== 'cer' ? (
              <div className="mt-5 px-2 text-[11px] text-stone-500 text-center leading-relaxed">
                Ao finalizar a compra, concordo com os <a href="https://ead.reikitimeacademy.com.br/termos-de-uso/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Termos de Uso</a> e <a href="https://ead.reikitimeacademy.com.br/politica-de-privacidade/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Política de Privacidade</a>. Reconheço que, por se tratar de um infoproduto com acesso imediato, <strong>abro mão do direito de arrependimento de 7 dias</strong>, conforme a <a href="https://ead.reikitimeacademy.com.br/politica-de-reembolso/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Política de Reembolso</a>.
              </div>
            ) : (
              <div className="mt-5 px-2 text-[11px] text-stone-500 text-center leading-relaxed">
                Ao finalizar a compra, concordo com os <a href="https://ead.reikitimeacademy.com.br/termos-de-uso/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Termos de Uso</a>, <a href="https://ead.reikitimeacademy.com.br/politica-de-privacidade/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Privacidade</a> e com nossa <a href="https://ead.reikitimeacademy.com.br/politica-de-reembolso/" target="_blank" rel="noreferrer" className="underline hover:text-stone-800">Política de Reembolso</a>.
              </div>
            )}

            <div className="mt-4 flex justify-center">
              <Turnstile 
                ref={turnstileRef}
                siteKey="0x4AAAAAADneoKhkuUxCMoVf" 
                onSuccess={(token) => setTurnstileToken(token)}
                options={{ theme: 'light' }}
              />
            </div>
            <div className="mt-6 flex items-center justify-center gap-2 text-xs text-stone-400">
              <Lock className="w-3 h-3" /> Seus dados estão criptografados ({currency === 'BRL' ? 'Asaas' : 'Stripe'})
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}

// O App envelopa o formulário no Provedor do Stripe
export default function App() {
  return (
    <CheckoutForm />
  );
}
