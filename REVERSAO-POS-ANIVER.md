# 🎂→🔄 REVERSÃO PÓS-ANIVERSÁRIO (rodar em 03/07/2026, manhã BRT)

A promo de aniversário termina 02/07/2026 23:59:59 BRT. Depois disso:

## 1. mu-plugin (`reiki-backend.php` → `get_reiki_products`)
Restaurar preços (procure pelos comentários `🎂 ANIVER`):

| Produto | Promo (remover) | RESTAURAR para |
|---|---|---|
| cer | 1297 / 255 / 225 | **2197 / 425 / 375** |
| guardias | 597 / 120 / 105 | **997 / 200 / 175** (nome fica "Guardiãs do Clã") |
| reiki-florescer | 97 / 20 / 18 | **297 / 60 / 55** |
| mandalas-reiki | 29.90 / 6 / 6 | **197 / 40 / 35** |
| infinity | 67 / 17 / 17 | **697 / 135 / 120** |
| desafio-infinity | 67 / 17 / 17 | **497 / 96 / 85** |
| reiki-cristais | 47 / 10 / 9 | **597 / ?? / ??** (preço regular pós-promo — definir) |
| masterclass-chakras | 29.90 / 6 / 6 | **197 / 40 / 35** (sugerido) |
| cla-do-livro | 97 / 20 / 18 | **297 / 60 / 55** (sugerido) |
| negocio-magnetico | 29.90 / 6 / 6 | **97 / 20 / 18** |

Depois: subir o mu-plugin de novo.

## 2. Checkout (`reiki-checkout/src/App.tsx` → PRODUCTS)
Mesmos valores acima (comentários `🎂 ANIVER` marcam as linhas). Commit + push.

## 3. LP Reiki Florescer (`lps-reiki-time/src/pages/ReikiFlorescer/index.tsx`)
- Remover a faixa "🎂 FAIXA DE ANIVERSÁRIO" do topo.
- Restaurar price box: `12x de R$ 30,71 / ou R$ 297,00 à vista` (remover o riscado e o 🎂).

## 4. Páginas do evento
- PWA → aba links → **aniver26**: colocar em `off` (o PageGate redireciona pra ajuda).
- **bio-oferta**: colocar em `off` (ou reaproveitar pra próxima campanha).
- (O countdown da aniver26 já mostra "encerradas" sozinho após o horário, mas desligar é mais limpo.)

## 5. Conferir
- `?produto=cer` mostra 2.197 de novo.
- `?produto=infinity` mostra 697 (fim da promo permanente — decisão do Gabriel 01/07).
- Pendente ainda: preço regular pós-promo do reiki-cristais (597/?USD/?EUR) — definir na hora.
- LP Florescer sem faixa e com 297.
