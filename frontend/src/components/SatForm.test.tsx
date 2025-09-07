import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SatForm from './SatForm';
import { SatApiService } from '../services/api';

// Mock the API service
jest.mock('../services/api');

describe('SatForm', () => {
  const mockOnApiResponse = jest.fn();
  const mockSetIsLoading = jest.fn();

  beforeEach(() => {
    render(<SatForm onApiResponse={mockOnApiResponse} setIsLoading={mockSetIsLoading} isLoading={false} />);
  });

  test('renders the form correctly', () => {
    expect(screen.getByLabelText(/Certificado/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Llave Privada/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Contraseña/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Autenticar/i })).toBeInTheDocument();
  });

  test('shows validation errors for required fields', async () => {
    fireEvent.click(screen.getByRole('button', { name: /Autenticar/i }));

    expect(await screen.findByText(/El certificado es requerido/i)).toBeInTheDocument();
    expect(await screen.findByText(/La llave privada es requerida/i)).toBeInTheDocument();
    expect(await screen.findByText(/La contraseña es requerida/i)).toBeInTheDocument();
  });

  test('submits the form with valid data', async () => {
    const certificateFile = new File(['certificate'], 'certificate.cer', { type: 'application/x-x509-ca-cert' });
    const privateKeyFile = new File(['privatekey'], 'privatekey.key', { type: 'application/octet-stream' });

    fireEvent.change(screen.getByLabelText(/Certificado/i), { target: { files: [certificateFile] } });
    fireEvent.change(screen.getByLabelText(/Llave Privada/i), { target: { files: [privateKeyFile] } });
    fireEvent.change(screen.getByLabelText(/Contraseña/i), { target: { value: 'password123' } });

    (SatApiService.authenticate as jest.Mock).mockResolvedValue({ success: true, data: {} });

    fireEvent.click(screen.getByRole('button', { name: /Autenticar/i }));

    await waitFor(() => {
      expect(mockSetIsLoading).toHaveBeenCalledWith(true);
      expect(SatApiService.authenticate).toHaveBeenCalled();
      expect(mockOnApiResponse).toHaveBeenCalled();
      expect(mockSetIsLoading).toHaveBeenCalledWith(false);
    });
  });
});
