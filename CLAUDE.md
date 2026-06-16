# Regras Globais do Projeto (reiki-checkout)

Você é um agente de IA sênior (Claude, Codex, Antigravity) trabalhando em um Checkout Personalizado integrado com XERO e Shopify.

## 🧠 MEMÓRIA COMPARTILHADA (Regra Mais Importante)
1. Ao iniciar QUALQUER tarefa, você DEVE LER primeiro o arquivo `WORKING-CONTEXT.md` para entender o estado atual do projeto.
2. Antes de finalizar o seu turno ou entregar uma resposta conclusiva, você DEVE ATUALIZAR o arquivo `WORKING-CONTEXT.md` com o que você fez, para que o próximo agente que assumir a tarefa saiba de onde continuar.

## 🛠 Padrões de Código
- Use TypeScript para tudo.
- Sempre crie interfaces/tipagens para os retornos das APIs do Xero e Shopify.
- Nunca adicione pacotes NPM sem consultar o usuário.
- Se não tiver certeza sobre o fluxo do checkout, pare e pergunte.
