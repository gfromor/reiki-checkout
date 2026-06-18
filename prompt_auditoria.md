# Instruções de Auditoria do Sistema de Checkout e Landing Page

**Atenção para a IA de Auditoria: LEIA ISTO PRIMEIRO.**
ESTA É UMA TAREFA ESTRITA DE AUDITORIA E LEITURA. VOCÊ ESTÁ PROIBIDA DE FAZER QUALQUER ALTERAÇÃO DE CÓDIGO, COMMIT, OU MODIFICAÇÃO DE ARQUIVOS. SEU ÚNICO OBJETIVO É LER, ANALISAR E REPORTAR.

## O Que é o Sistema?
Temos um ecossistema composto por:
1. **Landing Page (Next.js):** Localizada no repositório `Reiki-Time-Academy`. Responsável por capturar os dados iniciais do usuário e redirecionar para o Checkout.
2. **Checkout Customizado (Vite + React):** Localizado no repositório `reiki-checkout`. Um checkout transparente otimizado para conversão que se integra com Asaas (Pix/Boleto/Cartão Brasil) e Stripe (Cartão Internacional).
3. **Backend WordPress (API):** Recebe os pagamentos via Webhook/API, valida os dados e matricula os alunos no WooCommerce Memberships.

## Onde Encontrar os Arquivos para Analisar?
Os códigos atualizados já estão na sua máquina local nestes caminhos:

*   **Frontend Checkout:** `/Users/romor/antigravity/reiki-checkout/`
    *   *Arquivo principal para análise:* `src/App.tsx` e lógica de `fetch`.
*   **Landing Page:** `/Users/romor/antigravity/Reiki-Time-Academy/`
    *   *Arquivo principal para análise:* `src/components/CheckoutModal.tsx`
*   **Backend WordPress (WPCode):** Você pode analisar o snippet PHP responsável pela criação dos usuários e webhooks que foi fornecido pelo usuário ou se encontra documentado nos logs.

## O Que Você Deve Procurar?
Por favor, gere um relatório detalhado focado estritamente em **Segurança** e **Estabilidade do Sistema**. 
Analise os seguintes pontos:
1.  **Vazamento de Dados:** As chaves de API secretas (Stripe/Asaas) estão expostas no Frontend? O frontend processa os dados de forma segura?
2.  **Tratamento de Erros (Error Handling):** O que acontece se a API da Asaas/Stripe ou do WordPress cair no momento do pagamento? Os blocos `try/catch` estão bem estruturados?
3.  **Segurança de Webhooks:** O endpoint REST criado no WordPress está validando a origem da requisição corretamente para evitar que um atacante simule um pagamento falso? (Verifique se a lógica PHP checa tokens ou IPs, ou se depende do segredo do Stripe/Asaas).
4.  **Edge Cases Financeiros:** Existem cenários onde o dinheiro pode ser cobrado, mas o aluno não ser criado no WooCommerce Memberships? 
5.  **Performance & Gargalos:** Existe algum loop, renderização desnecessária pesada ou timeout que possa frustrar o cliente na hora de apertar o botão de pagar?

## Formato da Entrega
Entregue o resultado na forma de um relatório markdown (`.md`).
Divida em:
*   🟢 **Pontos Fortes (O que está bem feito e seguro)**
*   🟡 **Avisos (Pequenas melhorias de estabilidade ou boas práticas)**
*   🔴 **Riscos Críticos (Se houver alguma falha de segurança que precisa de atenção imediata)**

Repetindo: NÃO ALTERE NENHUM ARQUIVO. Apenas entregue o relatório em formato de texto para que a equipe humana decida os próximos passos.
