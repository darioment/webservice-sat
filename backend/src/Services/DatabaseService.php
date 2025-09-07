<?php

namespace SatApi\Services;

use PDO;
use PDOException;
use SatApi\Config\Config;
use Exception;

class DatabaseService
{
    private ?PDO $connection = null;

    public function connect(): PDO
    {
        if ($this->connection === null) {
            try {
                $config = Config::get('database');
                $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
                
                $this->connection = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $config['options']
                );
            } catch (PDOException $e) {
                throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }

        return $this->connection;
    }

    public function saveSatData(array $data): int
    {
        try {
            $pdo = $this->connect();
            
            $sql = "INSERT INTO sat_consultas (
                rfc,
                tipo_servicio,
                token_valido_hasta,
                token_creado,
                id_solicitud,
                estado,
                mensaje,
                fecha_consulta,
                certificado,
                llave,
                password
            ) VALUES (
                :rfc,
                :tipo_servicio,
                :token_valido_hasta,
                :token_creado,
                :id_solicitud,
                :estado,
                :mensaje,
                NOW(),
                :certificado,
                :llave,
                :password
            )";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'rfc' => $data['rfc'],
                'tipo_servicio' => $data['tipo_servicio'],
                'token_valido_hasta' => $data['token_valido_hasta'],
                'token_creado' => $data['token_creado'],
                'id_solicitud' => $data['id_solicitud'] ?? null,
                'estado' => $data['estado'],
                'mensaje' => $data['mensaje'],
                'certificado' => $data['certificado'],
                'llave' => $data['llave'],
                'password' => $data['password']
            ]);

            if (!$result) {
                throw new Exception("Error al ejecutar la consulta de inserción");
            }

            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new Exception("Error al guardar los datos: " . $e->getMessage());
        }
    }

    public function getConsultaById(int $id): ?array
    {
        try {
            $pdo = $this->connect();
            
            $sql = "SELECT * FROM sat_consultas WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            throw new Exception("Error al obtener la consulta: " . $e->getMessage());
        }
    }

    public function testConnection(): bool
    {
        try {
            $pdo = $this->connect();
            $stmt = $pdo->query("SELECT 1");
            return $stmt !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function closeConnection(): void
    {
        $this->connection = null;
    }
}