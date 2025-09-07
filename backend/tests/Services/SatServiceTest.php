<?php

namespace SatApi\Tests\Services;

use PHPUnit\Framework\TestCase;
use SatApi\Services\SatService;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\Shared\Token;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryResult;
use PhpCfdi\SatWsDescargaMasiva\Services\Verify\VerifyResult;
use PhpCfdi\SatWsDescargaMasiva\Shared\StatusCode;
use DateTimeImmutable;

class SatServiceTest extends TestCase
{
    private $serviceMock;
    private $satService;

    protected function setUp(): void
    {
        $this->serviceMock = $this->createMock(Service::class);
        $this->satService = new SatService();

        // Use reflection to set the protected service property
        $reflection = new \ReflectionClass($this->satService);
        $property = $reflection->getProperty('service');
        $property->setAccessible(true);
        $property->setValue($this->satService, $this->serviceMock);
    }

    public function testAuthenticate()
    {
        $token = new Token(new DateTimeImmutable(), new DateTimeImmutable(), 'sometoken');
        $this->serviceMock->method('authenticate')->willReturn($token);

        $result = $this->satService->authenticate('cert_content', 'key_content', 'password');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('token', $result);
    }

    public function testProcessQuery()
    {
        $status = new StatusCode(5000, 'Solicitud Aceptada');
        $queryResult = new QueryResult('request_id', $status);
        $this->serviceMock->method('query')->willReturn($queryResult);

        $params = [
            'startDate' => '2024-01-01',
            'endDate' => '2024-01-31',
        ];

        $result = $this->satService->processQuery($params);

        $this->assertTrue($result['success']);
        $this->assertEquals('request_id', $result['requestId']);
    }

    public function testVerifyRequest()
    {
        $status = new StatusCode(5000, 'Solicitud Aceptada');
        $verifyResult = new VerifyResult($status, $status, $status);
        $this->serviceMock->method('verify')->willReturn($verifyResult);

        $result = $this->satService->verifyRequest('request_id');

        $this->assertTrue($result['success']);
    }
}
