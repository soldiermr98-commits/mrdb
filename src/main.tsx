import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import { ErrorBoundary } from './components/common/ErrorBoundary.tsx';
import './index.css';

// BUG-54: React.StrictMode deliberately OFF (decision made — mock services run
// on intervals/delays that double-firing in StrictMode would distort).
// DEV-3: <App/> is wrapped in an ErrorBoundary (inside the render, not around
// main.tsx) so a render crash shows the Persian fallback card instead of a
// blank screen.
createRoot(document.getElementById('root')!).render(
  <ErrorBoundary>
    <App />
  </ErrorBoundary>
);
