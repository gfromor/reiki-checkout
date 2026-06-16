import React, { useState } from 'react';
import { CreditCard, QrCode, Receipt, ShieldCheck, Lock, Globe, Loader2, CheckCircle2 } from 'lucide-react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, CardElement, useStripe, useElements } from '@stripe/react-stripe-js';

// Chave Pública do Stripe (Pegue no Painel da Reiki Time Academy)
const stripePromise = loadStripe('pk_test_COLOQUE_A_CHAVE_PUBLICA_AQUI');

const INTEREST_RATES: Record<number, number> = {
  1: 0, 2: 7, 3: 8, 4: 9, 5: 10, 6: 11,
  7: 12, 8: 13, 9: 14, 10: 16, 11: 18, 12: 20
};

const PRODUCTS: Record<string, { title: string, subtitle: string, brlPrice: number, brlOriginal: number, usdPrice: number, eurPrice: number }> = {
  'cuidar': { 
    title: 'Formação Método CUIDAR', 
    subtitle: 'Acesso completo + Bônus exclusivos',
    brlPrice: 647.00, brlOriginal: 997.00,
    usdPrice: 119.00, eurPrice: 109.00 
  },
  'ebook': { 
    title: 'E-book Reiki Essencial', 
    subtitle: 'Download imediato (PDF)',
    brlPrice: 47.00, brlOriginal: 97.00,
    usdPrice: 12.00, eurPrice: 11.00 
  },
};

type PaymentMethod = 'credit_card' | 'pix' | 'boleto';
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
  const stripe = useStripe();
  const elements = useElements();

  const [currency, setCurrency] = useState<Currency>('BRL');
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('credit_card');
  const [installments, setInstallments] = useState<number>(1);
  
  const [nome, setNome] = useState('');
  const [email, setEmail] = useState('');
  const [telefone, setTelefone] = useState('');
  const [cpf, setCpf] = useState('');
  
  const [ccNumber, setCcNumber] = useState('');
  const [ccName, setCcName] = useState('');
  const [ccExpiry, setCcExpiry] = useState('');
  const [ccCvv, setCcCvv] = useState('');

  const [errors, setErrors] = useState<Record<string, string>>({});
  
  // Status de Transação (Carregamento e Sucesso)
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [pixData, setPixData] = useState<{ qrcode: string, copia_cola: string } | null>(null);

  const searchParams = new URLSearchParams(window.location.search);
  const productId = searchParams.get('produto') || 'cuidar';
  const product = PRODUCTS[productId] || PRODUCTS['cuidar'];
  
  const getProductPrice = () => {
    if (currency === 'USD') return product.usdPrice;
    if (currency === 'EUR') return product.eurPrice;
    return product.brlPrice;
  };
  
  const productPrice = getProductPrice();
  
  const calculateTotal = () => {
    if (currency === 'BRL' && paymentMethod === 'credit_card') {
      const rate = INTEREST_RATES[installments] || 0;
      return productPrice * (1 + rate / 100);
    }
    return productPrice;
  };

  const total = calculateTotal();
  const installmentValue = currency === 'BRL' ? total / installments : total;

  const handleCheckout = async (e: React.FormEvent) => {
    e.preventDefault();
    setServerError(null);
    const newErrors: Record<string, string> = {};

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
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }
    setErrors({});
    setIsLoading(true);

    // 1. Preparar o Payload
    const payload: any = {
      nome, email, telefone, cpf,
      produto: productId,
      valorTotal: total,
      gateway: currency === 'BRL' ? 'asaas' : 'stripe',
      currency: currency,
      parcelas: installments,
      metodo: paymentMethod
    };

    // 2. Se for Stripe, geramos o Token primeiro
    if (currency !== 'BRL') {
      if (!stripe || !elements) {
        setServerError('O sistema de cartão não carregou corretamente. Recarregue a página.');
        setIsLoading(false);
        return;
      }
      const cardElement = elements.getElement(CardElement);
      const { error, paymentMethod: stripePaymentMethod } = await stripe.createPaymentMethod({
        type: 'card',
        card: cardElement!,
        billing_details: { name: nome, email: email }
      });

      if (error) {
        setServerError(error.message || 'Erro no cartão');
        setIsLoading(false);
        return;
      }
      payload.stripe_payment_method = stripePaymentMethod.id;
    } 
    // 3. Se for Asaas Cartão, pegamos os dados diretos
    else if (paymentMethod === 'credit_card') {
      payload.cc_number = ccNumber;
      payload.cc_name = ccName;
      payload.cc_expiry = ccExpiry;
      payload.cc_cvv = ccCvv;
    }

    // 4. Enviar para o WordPress (EAD)
    try {
      const response = await fetch('https://ead.reikitimeacademy.com.br/wp-json/reiki/v1/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await response.json();

      if (!response.ok) {
        setServerError(data.message || 'Erro no processamento da compra.');
      } else {
        setIsSuccess(true);
        if (data.metodo === 'PIX') {
          setPixData({ qrcode: data.pix_qrcode, copia_cola: data.pix_copia_cola });
        }
      }
    } catch (err) {
      setServerError('Falha de conexão com o servidor. Verifique sua internet.');
    } finally {
      setIsLoading(false);
    }
  };

  // Tela de Sucesso
  if (isSuccess) {
    return (
      <div className="min-h-screen bg-stone-50 font-sans flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-2xl shadow-sm border border-stone-200 max-w-md w-full text-center animate-in zoom-in duration-300">
          <CheckCircle2 className="w-20 h-20 text-emerald-500 mx-auto mb-6" />
          <h2 className="text-2xl font-bold text-stone-800 mb-2">Pedido Confirmado!</h2>
          
          {pixData ? (
            <div className="mt-6 text-left">
              <p className="text-stone-600 mb-4 text-center">Escaneie o QR Code abaixo para pagar:</p>
              <img src={`data:image/png;base64,${pixData.qrcode}`} alt="PIX QR Code" className="mx-auto w-48 h-48 border rounded-lg p-2 mb-4" />
              <div className="bg-stone-100 p-3 rounded-lg text-xs break-all border border-stone-200">
                {pixData.copia_cola}
              </div>
              <p className="text-xs text-stone-500 text-center mt-3">A liberação do seu acesso será automática assim que o Pix for pago.</p>
            </div>
          ) : (
            <p className="text-stone-600 mt-4">Você receberá os dados de acesso no seu e-mail em instantes. Bem-vindo(a) à formação!</p>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-stone-50 font-sans text-stone-800 pb-12">
      <header className="bg-white border-b border-stone-200 py-4 px-6 md:px-12 flex items-center justify-between sticky top-0 z-10">
        <div className="font-bold text-xl tracking-tight text-emerald-800">Reiki Time Academy</div>
        
        <div className="flex bg-stone-100 p-1 rounded-lg border border-stone-200">
          <button type="button" onClick={() => { setCurrency('BRL'); setPaymentMethod('credit_card'); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'BRL' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
            🇧🇷 BRL
          </button>
          <button type="button" onClick={() => { setCurrency('USD'); setPaymentMethod('credit_card'); setInstallments(1); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'USD' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
            🇺🇸 USD
          </button>
          <button type="button" onClick={() => { setCurrency('EUR'); setPaymentMethod('credit_card'); setInstallments(1); }} className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all flex items-center gap-2 ${currency === 'EUR' ? 'bg-white shadow-sm text-stone-800' : 'text-stone-500 hover:text-stone-700'}`}>
            🇪🇺 EUR
          </button>
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
                  <input value={cpf} onChange={e=>setCpf(e.target.value)} type="text" className={`w-full border ${errors.cpf ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="000.000.000-00" />
                  {errors.cpf && <p className="text-red-500 text-xs mt-1">{errors.cpf}</p>}
                </div>
              )}
            </div>
          </section>

          <section className="bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-stone-200">
            <h2 className="text-xl font-bold mb-6 flex items-center gap-2">
              <span className="bg-emerald-100 text-emerald-700 w-6 h-6 flex items-center justify-center rounded-full text-sm">2</span>
              Pagamento {currency !== 'BRL' && <span className="ml-2 text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-full font-bold">Via Stripe</span>}
            </h2>
            
            {currency === 'BRL' && (
              <div className="grid grid-cols-3 gap-2 md:gap-4 mb-6">
                <button type="button" onClick={() => { setPaymentMethod('credit_card'); setInstallments(1); }} className={`p-3 md:p-4 border rounded-xl flex flex-col items-center justify-center gap-2 transition-all ${paymentMethod === 'credit_card' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <CreditCard className="w-6 h-6" />
                  <span className="text-xs md:text-sm font-medium">Cartão</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('pix'); setInstallments(1); }} className={`p-3 md:p-4 border rounded-xl flex flex-col items-center justify-center gap-2 transition-all ${paymentMethod === 'pix' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <QrCode className="w-6 h-6" />
                  <span className="text-xs md:text-sm font-medium">Pix</span>
                </button>
                <button type="button" onClick={() => { setPaymentMethod('boleto'); setInstallments(1); }} className={`p-3 md:p-4 border rounded-xl flex flex-col items-center justify-center gap-2 transition-all ${paymentMethod === 'boleto' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300 text-stone-500'}`}>
                  <Receipt className="w-6 h-6" />
                  <span className="text-xs md:text-sm font-medium">Boleto à vista</span>
                </button>
              </div>
            )}

            {currency === 'BRL' && paymentMethod === 'credit_card' && (
              <div className="space-y-4 animate-in fade-in">
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
                  <input value={ccName} onChange={e=>setCcName(e.target.value)} type="text" className={`w-full border ${errors.ccName ? 'border-red-500' : 'border-stone-300'} rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none`} placeholder="Ex: GABRIEL F ROMOR" />
                  {errors.ccName && <p className="text-red-500 text-xs mt-1">{errors.ccName}</p>}
                </div>
                
                <div>
                  <label className="block text-sm font-medium text-stone-600 mb-1">Parcelamento</label>
                  <select value={installments} onChange={(e) => setInstallments(Number(e.target.value))} className="w-full border border-stone-300 rounded-lg p-3 focus:ring-2 focus:ring-emerald-500 outline-none bg-white">
                    {[1,2,3,4,5,6,7,8,9,10,11,12].map(num => {
                      const rate = INTEREST_RATES[num] || 0;
                      const instTotal = productPrice * (1 + rate / 100);
                      const instValue = instTotal / num;
                      return (
                        <option key={num} value={num}>
                          {num}x de {instValue.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })} {num === 1 ? 'sem juros' : ''}
                        </option>
                      )
                    })}
                  </select>
                </div>
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

            {currency !== 'BRL' && (
              <div className="space-y-4 animate-in fade-in">
                <div className="bg-stone-50 border border-stone-200 rounded-lg p-4">
                  <label className="block text-sm font-medium text-stone-600 mb-2">Dados do Cartão (Protegido por Stripe)</label>
                  <div className="p-3 bg-white border border-stone-300 rounded-md">
                    <CardElement options={{
                      style: { base: { fontSize: '16px', color: '#424770', '::placeholder': { color: '#aab7c4' } } }
                    }} />
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
              <div className="w-20 h-20 bg-stone-100 rounded-lg border border-stone-200 flex-shrink-0 flex items-center justify-center">
                <span className="text-xs text-stone-400 font-medium">Imagem</span>
              </div>
              <div>
                <h4 className="font-semibold text-stone-800 leading-tight">{product.title}</h4>
                <p className="text-sm text-stone-500 mt-1">{product.subtitle}</p>
              </div>
            </div>

            <div className="space-y-3 mb-6">
              <div className="flex justify-between text-sm text-stone-600">
                <span>Valor com desconto</span>
                <span>
                  {currency === 'BRL' && productPrice.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                  {currency === 'USD' && productPrice.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                  {currency === 'EUR' && productPrice.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                </span>
              </div>
              {currency === 'BRL' && paymentMethod === 'credit_card' && installments > 1 && (
                <div className="flex justify-between text-sm text-amber-600">
                  <span>Juros de parcelamento ({installments}x)</span>
                  <span>+ {(total - productPrice).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</span>
                </div>
              )}
            </div>

            <div className="border-t pt-4 mb-8">
              <div className="flex justify-between items-end">
                <span className="font-medium text-stone-600">Total</span>
                <div className="text-right">
                  <span className="block text-2xl font-bold text-stone-800">
                    {currency === 'BRL' && total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                    {currency === 'USD' && total.toLocaleString('en-US', { style: 'currency', currency: 'USD' })}
                    {currency === 'EUR' && total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })}
                  </span>
                  {currency === 'BRL' && paymentMethod === 'credit_card' && installments > 1 && (
                    <span className="text-sm text-emerald-600 font-medium">
                      ou {installments}x de {installmentValue.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                    </span>
                  )}
                </div>
              </div>
            </div>

            <button disabled={isLoading} type="submit" className={`w-full text-white font-bold py-4 rounded-xl shadow-lg transition-all text-lg flex items-center justify-center gap-2 disabled:opacity-50 ${currency === 'BRL' ? 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-200' : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-200'}`}>
              {isLoading ? <><Loader2 className="w-5 h-5 animate-spin" /> Processando...</> : <><ShieldCheck className="w-5 h-5" /> Finalizar Compra {currency !== 'BRL' ? 'Internacional' : 'Segura'}</>}
            </button>
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
    <Elements stripe={stripePromise}>
      <CheckoutForm />
    </Elements>
  );
}
