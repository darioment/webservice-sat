/**
 * Represents the structure of the form data for a SAT request.
 */
export interface SatFormData {
  certificate: File | null;
  privateKey: File | null;
  password: string;
  startDate: string;
  endDate: string;
  documentType: 'ingreso' | 'egreso';
  downloadType: 'issued' | 'received';
}

/**
 * Represents the structure of a successful SAT API response.
 */
export interface SatResponseData {
  rfc: string;
  tokenValidUntil: string;
  tokenCreated: string;
  serviceType: string;
  requestId?: string;
  status?: string;
  message?: string;
  downloadType?: string;
  queryError?: string;
  verificationStatus?: string;
  verificationMessage?: string;
  packagesCount?: number;
  packages?: string[];
}

/**
 * Represents a successful API response envelope.
 */
export interface SatApiResponseSuccess {
  success: true;
  data: SatResponseData;
  dbId?: number;
}

/**
 * Represents a failed API response envelope.
 */
export interface SatApiResponseError {
  success: false;
  error: {
    code: string;
    message: string;
    details?: unknown;
  };
}

/**
 * Represents all possible API responses.
 */
export type SatApiResponse = SatApiResponseSuccess | SatApiResponseError;

/**
 * Represents the validation errors for the form.
 */
export interface FormErrors {
  certificate?: string;
  privateKey?: string;
  password?: string;
  startDate?: string;
  endDate?: string;
}
