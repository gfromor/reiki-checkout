import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import * as Sentry from '@sentry/react'
import './sentry'
import './index.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Sentry.ErrorBoundary
      fallback={
        <div style={{ padding: 24, textAlign: 'center', fontFamily: 'sans-serif', color: '#44403c' }}>
          <h2>Ops, algo deu errado.</h2>
          <p>Por favor, recarregue a página. Se o problema continuar, fale com nosso suporte.</p>
        </div>
      }
    >
      <App />
    </Sentry.ErrorBoundary>
  </StrictMode>,
)
