import * as Sentry from '@sentry/react';

// Monitor de erros do checkout. Configurado para NUNCA enviar PII (CPF, cartão, e-mail, etc.).
// - sem Session Replay (não grava a tela, onde há campos de cartão)
// - sem performance tracing (só erros)
// - URLs têm os parâmetros sensíveis redigidos antes do envio

const SENSITIVE_PARAMS = ['name', 'nome', 'email', 'phone', 'telefone', 'cpf', 'cupom'];

function scrubUrl(url?: string): string | undefined {
  if (!url) return url;
  try {
    const u = new URL(url, window.location.origin);
    SENSITIVE_PARAMS.forEach((p) => {
      if (u.searchParams.has(p)) u.searchParams.set(p, '[redacted]');
    });
    return u.toString();
  } catch {
    return url;
  }
}

Sentry.init({
  dsn: 'https://5291d9aecf4fd8e79532f0bf2984eefd@o4511648853393408.ingest.us.sentry.io/4511648892256256',
  environment: 'production',
  sendDefaultPii: false,
  tracesSampleRate: 0,
  beforeBreadcrumb(breadcrumb) {
    if (breadcrumb?.data && typeof breadcrumb.data.url === 'string') {
      breadcrumb.data.url = scrubUrl(breadcrumb.data.url);
    }
    return breadcrumb;
  },
  beforeSend(event) {
    if (event.request?.url) event.request.url = scrubUrl(event.request.url);
    if (event.request) {
      delete (event.request as Record<string, unknown>).cookies;
      delete (event.request as Record<string, unknown>).headers;
    }
    return event;
  },
});
