import { render, screen } from '@testing-library/react';
import ResultsDisplay from './ResultsDisplay';
import type { SatApiResponse } from '../types/sat';

describe('ResultsDisplay', () => {
  test('renders loading state', () => {
    render(<ResultsDisplay response={null} isLoading={true} />);
    expect(screen.getByText(/Cargando/i)).toBeInTheDocument();
  });

  test('renders nothing when no response and not loading', () => {
    const { container } = render(<ResultsDisplay response={null} isLoading={false} />);
    expect(container).toBeEmptyDOMElement();
  });

  test('renders error state', () => {
    const errorResponse: SatApiResponse = {
      success: false,
      error: {
        code: 'TEST_ERROR',
        message: 'This is a test error',
      },
    };
    render(<ResultsDisplay response={errorResponse} isLoading={false} />);
    expect(screen.getByText(/Error/i)).toBeInTheDocument();
    expect(screen.getByText(/TEST_ERROR/i)).toBeInTheDocument();
    expect(screen.getByText(/This is a test error/i)).toBeInTheDocument();
  });

  test('renders success state', () => {
    const successResponse: SatApiResponse = {
      success: true,
      data: {
        rfc: 'TEST010101010',
        serviceType: 'CFDI',
        tokenCreated: '2025-01-01T11:00:00Z',
        tokenValidUntil: '2025-01-01T12:00:00Z',
      },
    };
    render(<ResultsDisplay response={successResponse} isLoading={false} />);
    expect(screen.getByText(/Resultados de la Autenticación/i)).toBeInTheDocument();
    expect(screen.getByText(/TEST010101010/i)).toBeInTheDocument();
  });
});
