<?php

namespace SatApi\Tests\Utils;

use PHPUnit\Framework\TestCase;
use SatApi\Utils\ApiResponse;

class ApiResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // Capture output for testing
        ob_start();
    }

    protected function tearDown(): void
    {
        // Clean output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }
    }

    public function testFormatSatResponse(): void
    {
        $authResult = [
            'rfc' => 'TEST123456789',
            'tokenValidUntil' => '2024-01-01 12:00:00',
            'tokenCreated' => '2024-01-01 10:00:00'
        ];

        $response = ApiResponse::formatSatResponse($authResult);

        $this->assertArrayHasKey('rfc', $response);
        $this->assertArrayHasKey('tokenValidUntil', $response);
        $this->assertArrayHasKey('tokenCreated', $response);
        $this->assertArrayHasKey('serviceType', $response);
        $this->assertEquals('CFDI', $response['serviceType']);
        $this->assertEquals('TEST123456789', $response['rfc']);
    }

    public function testFormatSatResponseWithQuery(): void
    {
        $authResult = [
            'rfc' => 'TEST123456789',
            'tokenValidUntil' => '2024-01-01 12:00:00',
            'tokenCreated' => '2024-01-01 10:00:00'
        ];

        $queryResult = [
            'success' => true,
            'requestId' => 'test-request-id',
            'status' => 'ACCEPTED',
            'message' => 'Consulta exitosa',
            'downloadType' => 'Recibidos'
        ];

        $response = ApiResponse::formatSatResponse($authResult, $queryResult);

        $this->assertArrayHasKey('requestId', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('downloadType', $response);
        $this->assertEquals('test-request-id', $response['requestId']);
    }

    public function testFormatDatabaseData(): void
    {
        $authResult = [
            'rfc' => 'TEST123456789',
            'tokenValidUntil' => '2024-01-01 12:00:00',
            'tokenCreated' => '2024-01-01 10:00:00'
        ];

        $dbData = ApiResponse::formatDatabaseData($authResult);

        $this->assertArrayHasKey('rfc', $dbData);
        $this->assertArrayHasKey('tipo_servicio', $dbData);
        $this->assertArrayHasKey('token_valido_hasta', $dbData);
        $this->assertArrayHasKey('token_creado', $dbData);
        $this->assertArrayHasKey('id_solicitud', $dbData);
        $this->assertArrayHasKey('estado', $dbData);
        $this->assertArrayHasKey('mensaje', $dbData);
        
        $this->assertEquals('CFDI', $dbData['tipo_servicio']);
        $this->assertEquals('AUTHENTICATED', $dbData['estado']);
        $this->assertEquals('Autenticación exitosa', $dbData['mensaje']);
    }

    public function testSuccessResponseStructure(): void
    {
        $data = ['test' => 'value'];
        
        // Capture the output
        ob_start();
        ApiResponse::success($data, 200, 123);
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('dbId', $response);
        $this->assertTrue($response['success']);
        $this->assertEquals($data, $response['data']);
        $this->assertEquals(123, $response['dbId']);
    }

    public function testErrorResponseStructure(): void
    {
        ob_start();
        ApiResponse::error('Test error', 'TEST_ERROR', 'Test details', 400);
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('error', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('code', $response['error']);
        $this->assertArrayHasKey('message', $response['error']);
        $this->assertArrayHasKey('details', $response['error']);
        $this->assertEquals('TEST_ERROR', $response['error']['code']);
        $this->assertEquals('Test error', $response['error']['message']);
        $this->assertEquals('Test details', $response['error']['details']);
    }
}