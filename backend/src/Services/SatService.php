<?php

namespace SatApi\Services;

use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use GuzzleHttp\Client as GuzzleClient;
use PhpCfdi\SatWsDescargaMasiva\Shared\ServiceEndpoints;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;
use Exception;

class SatService
{
    private Service $service;
    private Fiel $fiel;

    public function authenticate(string $certificateContent, string $keyContent, string $password): array
    {
        try {
            // Create FIEL object
            $this->fiel = Fiel::create($certificateContent, $keyContent, $password);

            // Validate FIEL
            if (!$this->fiel->isValid()) {
                throw new Exception('La FIEL no es válida. Verifica que los archivos correspondan a una FIEL vigente.');
            }

            // Configure Guzzle Client
            $guzzleClient = new GuzzleClient([
                'timeout' => $_ENV['API_TIMEOUT'] ?? 120,
                'connect_timeout' => $_ENV['API_CONNECT_TIMEOUT'] ?? 60,
                'http_errors' => false,
                'verify' => true,
                'headers' => [
                    'User-Agent' => 'phpcfdi/sat-ws-descarga-masiva-guzzle',
                    'Accept' => 'application/xml',
                    'Cache-Control' => 'no-cache'
                ]
            ]);

            // Create web client
            $webClient = new GuzzleWebClient($guzzleClient);

            // Create request builder
            $requestBuilder = new FielRequestBuilder($this->fiel);

            // Create service
            $this->service = new Service(
                $requestBuilder,
                $webClient,
                null,
                ServiceEndpoints::cfdi()
            );

            // Authenticate
            $token = $this->service->authenticate();

            return [
                'success' => true,
                'rfc' => $this->fiel->getRfc(),
                'tokenValidUntil' => $token->getExpires()->format('Y-m-d H:i:s'),
                'tokenCreated' => $token->getCreated()->format('Y-m-d H:i:s'),
                'token' => $token
            ];

        } catch (Exception $e) {
            throw new Exception('Error en la autenticación SAT: ' . $e->getMessage());
        }
    }

    public function processQuery(array $params): array
    {
        if (!isset($this->service)) {
            throw new Exception('Debe autenticarse primero antes de realizar consultas');
        }

        try {
            // Extract parameters
            $startDate = $params['startDate'] ?? '';
            $endDate = $params['endDate'] ?? '';
            $documentType = $params['documentType'] ?? 'egreso';
            $downloadType = $params['downloadType'] ?? 'received';

            if (empty($startDate) || empty($endDate)) {
                throw new Exception('Las fechas de inicio y fin son requeridas para realizar consultas');
            }

            // Create query parameters
            $request = QueryParameters::create()
                ->withPeriod(DateTimePeriod::createFromValues($startDate . ' 00:00:00', $endDate . ' 23:59:59'))
                ->withRequestType(RequestType::xml())
                ->withDownloadType($downloadType === 'issued' ? DownloadType::issued() : DownloadType::received())
                ->withDocumentType($documentType === 'ingreso' ? DocumentType::ingreso() : DocumentType::egreso())
                ->withDocumentStatus(DocumentStatus::active());

            // Execute query
            $query = $this->service->query($request);

            // Check query status
            if (!$query->getStatus()->isAccepted()) {
                return [
                    'success' => false,
                    'error' => 'Fallo al presentar la consulta: ' . $query->getStatus()->getMessage(),
                    'requestId' => null,
                    'status' => $query->getStatus()->getCode(),
                    'message' => $query->getStatus()->getMessage()
                ];
            }

            return [
                'success' => true,
                'requestId' => $query->getRequestId(),
                'status' => $query->getStatus()->getCode(),
                'message' => $query->getStatus()->getMessage(),
                'downloadType' => $downloadType === 'issued' ? 'Emitidos' : 'Recibidos'
            ];

        } catch (Exception $e) {
            throw new Exception('Error en la consulta SAT: ' . $e->getMessage());
        }
    }

    public function verifyRequest(string $requestId): array
    {
        if (!isset($this->service)) {
            throw new Exception('Debe autenticarse primero antes de verificar solicitudes');
        }

        try {
            $verify = $this->service->verify($requestId);

            // Check verification status
            if (!$verify->getStatus()->isAccepted()) {
                return [
                    'success' => false,
                    'error' => "Fallo al verificar la consulta {$requestId}: " . $verify->getStatus()->getMessage(),
                    'requestId' => $requestId
                ];
            }

            // Check if request was rejected
            if (!$verify->getCodeRequest()->isAccepted()) {
                return [
                    'success' => false,
                    'error' => "La solicitud {$requestId} fue rechazada: " . $verify->getCodeRequest()->getMessage(),
                    'requestId' => $requestId
                ];
            }

            // Check request status
            $statusRequest = $verify->getStatusRequest();
            
            if ($statusRequest->isExpired() || $statusRequest->isFailure() || $statusRequest->isRejected()) {
                return [
                    'success' => false,
                    'error' => "La solicitud {$requestId} no se puede completar",
                    'requestId' => $requestId,
                    'status' => 'failed'
                ];
            }

            if ($statusRequest->isInProgress() || $statusRequest->isAccepted()) {
                return [
                    'success' => true,
                    'requestId' => $requestId,
                    'status' => 'processing',
                    'message' => "La solicitud {$requestId} se está procesando"
                ];
            }

            if ($statusRequest->isFinished()) {
                $packages = [];
                foreach ($verify->getPackagesIds() as $packageId) {
                    $packages[] = $packageId;
                }

                return [
                    'success' => true,
                    'requestId' => $requestId,
                    'status' => 'finished',
                    'message' => "La solicitud {$requestId} está lista",
                    'packagesCount' => $verify->countPackages(),
                    'packages' => $packages
                ];
            }

            return [
                'success' => true,
                'requestId' => $requestId,
                'status' => 'unknown',
                'message' => 'Estado de la solicitud desconocido'
            ];

        } catch (Exception $e) {
            throw new Exception('Error en la verificación SAT: ' . $e->getMessage());
        }
    }

    public function getRfc(): ?string
    {
        return isset($this->fiel) ? $this->fiel->getRfc() : null;
    }

    public function isAuthenticated(): bool
    {
        return isset($this->service) && isset($this->fiel);
    }
}