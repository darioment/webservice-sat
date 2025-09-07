import React, { useState, useCallback } from 'react';
import type { SatFormData, FormErrors, SatApiResponse } from '../types/sat';
import { SatApiService } from '../services/api';

interface SatFormProps {
  onApiResponse: (response: SatApiResponse) => void;
  setIsLoading: (isLoading: boolean) => void;
  isLoading: boolean;
}

const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

const SatForm: React.FC<SatFormProps> = ({ onApiResponse, setIsLoading, isLoading }) => {
  const [formData, setFormData] = useState<SatFormData>({
    certificate: null,
    privateKey: null,
    password: '',
    startDate: '',
    endDate: '',
    documentType: 'egreso',
    downloadType: 'received',
  });

  const [errors, setErrors] = useState<FormErrors>({});

  const validateField = useCallback((name: keyof SatFormData, value: File | string | null) => {
    let error = '';
    switch (name) {
      case 'certificate':
        if (!value) error = 'El certificado es requerido';
        else if (value instanceof File) {
          if (value.size > MAX_FILE_SIZE) error = 'El archivo no debe exceder los 5MB';
          else if (!value.name.endsWith('.cer')) error = 'Debe ser un archivo .cer';
        }
        break;
      case 'privateKey':
        if (!value) error = 'La llave privada es requerida';
        else if (value instanceof File) {
          if (value.size > MAX_FILE_SIZE) error = 'El archivo no debe exceder los 5MB';
          else if (!value.name.endsWith('.key')) error = 'Debe ser un archivo .key';
        }
        break;
      case 'password':
        if (!value) error = 'La contraseña es requerida';
        break;
      case 'endDate':
        if (formData.startDate && value && typeof value === 'string' && value < formData.startDate) {
          error = 'La fecha de fin no puede ser anterior a la de inicio';
        }
        break;
    }
    setErrors((prev) => ({ ...prev, [name]: error }));
  }, [formData.startDate]);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, files } = e.target;
    if (files && files.length > 0) {
      const file = files[0];
      setFormData((prev) => ({ ...prev, [name]: file }));
      validateField(name as keyof SatFormData, file);
    }
  };

  const handleBlur = (e: React.FocusEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    validateField(name as keyof SatFormData, value);
  };

  const validateForm = (): boolean => {
    validateField('certificate', formData.certificate);
    validateField('privateKey', formData.privateKey);
    validateField('password', formData.password);
    validateField('endDate', formData.endDate);
    
    // Re-check errors state after validation
    let isValid = true;
    Object.values(errors).forEach(error => {
        if(error) isValid = false;
    });

    return isValid;
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!validateForm()) return;

    setIsLoading(true);
    const response = await SatApiService.authenticate(formData);
    onApiResponse(response);
    setIsLoading(false);
  };

  return (
    <div className="max-w-2xl mx-auto p-6 bg-white rounded-lg shadow-md">
      <h2 className="text-2xl font-bold mb-6 text-gray-800">Autenticación SAT</h2>
      <form onSubmit={handleSubmit} noValidate>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Certificate File */}
          <div>
            <label htmlFor="certificate" className="block text-sm font-medium text-gray-700">Certificado (.cer)</label>
            <input
              type="file"
              name="certificate"
              id="certificate"
              onChange={handleFileChange}
              onBlur={handleBlur}
              accept=".cer"
              className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"/>
            {errors.certificate && <p className="text-red-500 text-xs mt-1">{errors.certificate}</p>}
          </div>

          {/* Private Key File */}
          <div>
            <label htmlFor="privateKey" className="block text-sm font-medium text-gray-700">Llave Privada (.key)</label>
            <input
              type="file"
              name="privateKey"
              id="privateKey"
              onChange={handleFileChange}
              onBlur={handleBlur}
              accept=".key"
              className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"/>
            {errors.privateKey && <p className="text-red-500 text-xs mt-1">{errors.privateKey}</p>}
          </div>

          {/* Password */}
          <div className="md:col-span-2">
            <label htmlFor="password" className="block text-sm font-medium text-gray-700">Contraseña</label>
            <input
              type="password"
              name="password"
              id="password"
              value={formData.password}
              onChange={handleInputChange}
              onBlur={handleBlur}
              className="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500"/>
            {errors.password && <p className="text-red-500 text-xs mt-1">{errors.password}</p>}
          </div>

          {/* Start Date */}
          <div>
            <label htmlFor="startDate" className="block text-sm font-medium text-gray-700">Fecha de Inicio</label>
            <input
              type="date"
              name="startDate"
              id="startDate"
              value={formData.startDate}
              onChange={handleInputChange}
              onBlur={handleBlur}
              className="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"/>
          </div>

          {/* End Date */}
          <div>
            <label htmlFor="endDate" className="block text-sm font-medium text-gray-700">Fecha de Fin</label>
            <input
              type="date"
              name="endDate"
              id="endDate"
              value={formData.endDate}
              onChange={handleInputChange}
              onBlur={handleBlur}
              className="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"/>
            {errors.endDate && <p className="text-red-500 text-xs mt-1">{errors.endDate}</p>}
          </div>

          {/* Document Type */}
          <div>
            <label htmlFor="documentType" className="block text-sm font-medium text-gray-700">Tipo de Documento</label>
            <select
              name="documentType"
              id="documentType"
              value={formData.documentType}
              onChange={handleInputChange}
              onBlur={handleBlur}
              className="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
              <option value="ingreso">Ingreso</option>
              <option value="egreso">Egreso</option>
            </select>
          </div>

          {/* Download Type */}
          <div>
            <label htmlFor="downloadType" className="block text-sm font-medium text-gray-700">Tipo de Descarga</label>
            <select
              name="downloadType"
              id="downloadType"
              value={formData.downloadType}
              onChange={handleInputChange}
              onBlur={handleBlur}
              className="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
              <option value="issued">Emitidos</option>
              <option value="received">Recibidos</option>
            </select>
          </div>
        </div>

        <div className="mt-6">
          <button
            type="submit"
            disabled={isLoading}
            className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:bg-gray-400">
            {isLoading ? 'Autenticando...' : 'Autenticar'}
          </button>
        </div>
      </form>
    </div>
  );
};

export default SatForm;
