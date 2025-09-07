import React from 'react';
import type { SatApiResponse } from '../types/sat';

interface ResultsDisplayProps {
  response: SatApiResponse | null;
  isLoading: boolean;
}

const ResultsDisplay: React.FC<ResultsDisplayProps> = ({ response, isLoading }) => {
  if (isLoading) {
    return (
      <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-md text-center">
        <p className="text-blue-700 font-semibold">Cargando...</p>
      </div>
    );
  }

  if (!response) {
    return null;
  }

  if (!response.success) {
    return (
      <div className="mt-6 p-4 bg-red-50 border border-red-200 rounded-md">
        <h3 className="text-lg font-bold text-red-800">Error</h3>
        <p className="text-red-700"><span className="font-semibold">Código:</span> {response.error.code}</p>
        <p className="text-red-700"><span className="font-semibold">Mensaje:</span> {response.error.message}</p>
        {response.error.details ? (
          <pre className="mt-2 p-2 bg-red-100 text-red-600 rounded text-xs">
            {(() => {
              try {
                return JSON.stringify(response.error.details, null, 2);
              } catch {
                return String(response.error.details);
              }
            })()}
          </pre>
        ) : null}
      </div>
    );
  }

  const { data } = response;

  return (
    <div className="mt-6 p-6 bg-green-50 border border-green-200 rounded-md shadow-sm">
      <h3 className="text-xl font-bold text-green-800 mb-4">Resultados de la Autenticación</h3>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div><span className="font-semibold text-gray-700">RFC:</span> {data.rfc}</div>
        <div><span className="font-semibold text-gray-700">Tipo de Servicio:</span> {data.serviceType}</div>
        <div><span className="font-semibold text-gray-700">Token Creado:</span> {data.tokenCreated}</div>
        <div><span className="font-semibold text-gray-700">Token Válido Hasta:</span> {data.tokenValidUntil}</div>
      </div>

      {data.requestId && (
        <div className="mt-4 pt-4 border-t border-green-200">
          <h4 className="text-lg font-bold text-green-800 mb-2">Resultados de la Consulta</h4>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div><span className="font-semibold text-gray-700">ID de Solicitud:</span> {data.requestId}</div>
            <div><span className="font-semibold text-gray-700">Estado:</span> {data.status}</div>
            <div className="md:col-span-2"><span className="font-semibold text-gray-700">Mensaje:</span> {data.message}</div>
            <div><span className="font-semibold text-gray-700">Tipo de Descarga:</span> {data.downloadType}</div>
          </div>
        </div>
      )}

      {data.verificationStatus && (
        <div className="mt-4 pt-4 border-t border-green-200">
          <h4 className="text-lg font-bold text-green-800 mb-2">Verificación de la Solicitud</h4>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div><span className="font-semibold text-gray-700">Estado:</span> {data.verificationStatus}</div>
            <div className="md:col-span-2"><span className="font-semibold text-gray-700">Mensaje:</span> {data.verificationMessage}</div>
            {data.packagesCount !== undefined && (
              <div><span className="font-semibold text-gray-700">Paquetes:</span> {data.packagesCount}</div>
            )}
          </div>
          {data.packages && data.packages.length > 0 && (
            <div className="mt-2">
              <h5 className="font-semibold text-gray-700">IDs de Paquetes:</h5>
              <ul className="list-disc list-inside bg-gray-50 p-2 rounded">
                {data.packages.map((pkg) => <li key={pkg} className="text-xs">{pkg}</li>)}
              </ul>
            </div>
          )}
        </div>
      )}

      {data.queryError && (
        <div className="mt-4 pt-4 border-t border-red-200">
          <h4 className="text-lg font-bold text-red-800 mb-2">Error en la Consulta</h4>
          <p className="text-red-700">{data.queryError}</p>
        </div>
      )}
    </div>
  );
};

export default ResultsDisplay;
