import type { SatFormData, SatApiResponse } from '../types/sat';

// Use relative path in development (handled by Vite proxy), full URL in production
const API_BASE_URL = import.meta.env.MODE === 'development' ? 'https://mas.usoreal.com/api/sat' : 'https://mas.usoreal.com/api/sat';
const AUTH_ENDPOINT = `${API_BASE_URL}/authenticate`;
const REQUEST_TIMEOUT = 30000; // 30 seconds

export const SatApiService = {
  authenticate: async (formData: SatFormData): Promise<SatApiResponse> => {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

    try {
      const body = new FormData();
      if (formData.certificate) body.append('certificate', formData.certificate);
      if (formData.privateKey) body.append('privateKey', formData.privateKey);
      body.append('password', formData.password);
      body.append('startDate', formData.startDate);
      body.append('endDate', formData.endDate);
      body.append('documentType', formData.documentType);
      body.append('downloadType', formData.downloadType);

      const response = await fetch(AUTH_ENDPOINT, {
        method: 'POST',
        body,
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        try {
          const errorData = await response.json();
          return errorData as SatApiResponse;
        } catch {
          return {
            success: false,
            error: {
              code: 'NETWORK_ERROR',
              message: `HTTP error! status: ${response.status}`,
            },
          };
        }
      }

      return (await response.json()) as SatApiResponse;
    } catch (error) {
      clearTimeout(timeoutId);
      if (error instanceof Error && error.name === 'AbortError') {
        return {
          success: false,
          error: {
            code: 'TIMEOUT_ERROR',
            message: 'La solicitud ha tardado demasiado tiempo en responder.',
          },
        };
      }
      return {
        success: false,
        error: {
          code: 'CLIENT_ERROR',
          message: error instanceof Error ? error.message : 'Ocurrió un error inesperado',
        },
      };
    }
  },
};
