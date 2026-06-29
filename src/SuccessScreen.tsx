import { CheckCircle2, Loader2 } from 'lucide-react';

interface SuccessScreenProps {
  pixData: { qrcode: string; copia_cola: string } | null;
  boletoUrl: string | null;
  paymentId: string | null;
  paymentConfirmed: boolean;
}

// Tela de sucesso do checkout (PIX/boleto/confirmado). Presentacional — recebe tudo por props.
export default function SuccessScreen({ pixData, boletoUrl, paymentId, paymentConfirmed }: SuccessScreenProps) {
  return (
    <div className="min-h-screen bg-stone-50 font-sans flex items-center justify-center p-4">
      <div className="bg-white p-8 rounded-2xl shadow-sm border border-stone-200 max-w-md w-full text-center animate-in zoom-in duration-300">
        <CheckCircle2 className="w-20 h-20 text-emerald-500 mx-auto mb-6" />
        <h2 className="text-2xl font-bold text-stone-800 mb-2">Pedido Confirmado!</h2>

        {paymentConfirmed ? (
          <div className="mt-6 bg-emerald-50 border border-emerald-200 rounded-xl p-6 animate-in fade-in">
            <p className="text-emerald-700 font-bold text-lg">✅ Pagamento confirmado!</p>
            <p className="text-emerald-600 text-sm mt-1">Seu acesso foi liberado. Confira seu e-mail. Bem-vindo(a) à formação! ✨</p>
          </div>
        ) : pixData || boletoUrl ? (
          <div className="mt-6 text-left">
            {pixData && (
              <>
                <p className="text-stone-600 mb-4 text-center">Escaneie o QR Code abaixo para pagar a sua entrada no Pix:</p>
                <img src={`data:image/png;base64,${pixData.qrcode}`} alt="PIX QR Code" className="mx-auto w-48 h-48 border rounded-lg p-2 mb-4" />
                <div className="bg-stone-100 p-3 rounded-lg text-xs break-all border border-stone-200">
                  {pixData.copia_cola}
                </div>
              </>
            )}
            {boletoUrl && (
              <div className="mt-6 border-t pt-6">
                <p className="text-stone-600 mb-4 text-center">Gere o seu boleto abaixo para o restante do pagamento:</p>
                <a href={boletoUrl} target="_blank" rel="noreferrer" className="block w-full py-4 bg-stone-800 hover:bg-stone-900 text-white rounded-xl font-bold text-center transition-colors">
                  📄 Visualizar Boleto
                </a>
                <p className="text-xs text-stone-500 text-center mt-3">
                  <strong>Atenção:</strong> O acesso ao curso será enviado para seu e-mail apenas após a compensação do Boleto.
                </p>
              </div>
            )}
            {!boletoUrl && pixData && (
              <p className="text-xs text-stone-500 text-center mt-3">A liberação do seu acesso será automática assim que o Pix for pago.</p>
            )}
            {paymentId && (
              <p className="text-xs text-emerald-600 text-center mt-4 flex items-center justify-center gap-2">
                <Loader2 className="w-3 h-3 animate-spin" /> Aguardando confirmação do pagamento...
              </p>
            )}
          </div>
        ) : (
          <p className="text-stone-600 mt-4">Você receberá os dados de acesso no seu e-mail em instantes. Bem-vindo(a) à formação!</p>
        )}
      </div>
    </div>
  );
}
