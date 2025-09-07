<?php

namespace SatApi\Tests\Services;

use PHPUnit\Framework\TestCase;
use SatApi\Services\DatabaseService;
use PDO;
use PDOStatement;

class DatabaseServiceTest extends TestCase
{
    private $pdo;
    private $stmt;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
    }

    public function testSaveSatData()
    {
        $data = [
            'rfc' => 'TEST010101010',
            'tipo_servicio' => 'CFDI',
            'token_valido_hasta' => '2025-01-01 12:00:00',
            'token_creado' => '2025-01-01 11:00:00',
            'id_solicitud' => '12345',
            'estado' => 'AUTHENTICATED',
            'mensaje' => 'Autenticación exitosa',
            'certificado' => 'cert_content',
            'llave' => 'key_content',
            'password' => 'password123'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) use ($data) {
                return $params[':rfc'] === $data['rfc'];
            }))
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $dbService = new DatabaseService();

        // Use reflection to set the protected pdo property
        $reflection = new \ReflectionClass($dbService);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $property->setValue($dbService, $this->pdo);

        $result = $dbService->saveSatData($data);

        $this->assertEquals(1, $result);
    }
}
