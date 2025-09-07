import { useState } from 'react';
import SatForm from './components/SatForm';
import ResultsDisplay from './components/ResultsDisplay';
import ErrorBoundary from './components/ErrorBoundary';
import type { SatApiResponse } from './types/sat';
import './App.css';

function App() {
  const [response, setResponse] = useState<SatApiResponse | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  const handleApiResponse = (res: SatApiResponse) => {
    setResponse(res);
  };

  return (
    <div className="min-h-screen bg-gray-100 py-10">
      <main className="container mx-auto px-4">
        <ErrorBoundary>
          <h1 className="text-4xl font-bold text-center text-gray-800 mb-8">Descarga Masiva SAT</h1>
          <SatForm 
            onApiResponse={handleApiResponse} 
            setIsLoading={setIsLoading} 
            isLoading={isLoading} 
          />
          <ResultsDisplay response={response} isLoading={isLoading} />
        </ErrorBoundary>
      </main>
    </div>
  );
}

export default App;
