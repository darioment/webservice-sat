# Design Document

## Overview

El sistema se dividirá en dos componentes principales: un backend API REST en PHP que mantendrá toda la lógica de negocio del SAT y un frontend SPA (Single Page Application) en React/TypeScript. La comunicación entre ambos será a través de HTTP/JSON, permitiendo escalabilidad y mantenimiento independiente de cada componente.

## Architecture

### High-Level Architecture

```
┌─────────────────┐    HTTP/JSON     ┌──────────────────┐
│                 │ ◄──────────────► │                  │
│  React Frontend │                  │   PHP Backend    │
│  (Port 5173)    │                  │   (Apache/Nginx) │
│                 │                  │                  │
└─────────────────┘                  └──────────────────┘
                                               │
                                               ▼
                                     ┌──────────────────┐
                                     │                  │
                                     │  MySQL Database  │
                                     │  (sat_consultas) │
                                     │                  │
                                     └──────────────────┘
```

### Backend Architecture (PHP)

- **API Layer**: Endpoints REST para manejar peticiones HTTP
- **Service Layer**: Lógica de negocio para autenticación SAT
- **Data Layer**: Conexión y operaciones con MySQL
- **File Handler**: Manejo seguro de archivos temporales

### Frontend Architecture (React/TypeScript)

- **Components**: Componentes reutilizables con Tailwind CSS
- **Services**: Capa de comunicación con el backend API
- **State Management**: React hooks para manejo de estado local
- **Types**: Definiciones TypeScript para type safety

## Components and Interfaces

### Backend Components

#### 1. API Router (`api/index.php`)
```php
// Maneja el routing de las peticiones API
POST /api/sat/authenticate
- Acepta multipart/form-data
- Valida archivos y parámetros
- Delega a SatService
```

#### 2. SAT Service (`src/Services/SatService.php`)
```php
class SatService {
    public function authenticate(array $files, array $params): array
    public function processQuery(Fiel $fiel, array $params): array
    public function verifyRequest(string $requestId): array
}
```

#### 3. Database Service (`src/Services/DatabaseService.php`)
```php
class DatabaseService {
    public function connect(): PDO
    public function saveSatData(array $data): int
    public function getConsultaById(int $id): ?array
}
```

#### 4. File Handler (`src/Utils/FileHandler.php`)
```php
class FileHandler {
    public function createTempFile(string $content): string
    public function cleanupTempFiles(array $files): void
    public function validateFileType(string $filename, array $allowedTypes): bool
}
```

### Frontend Components

#### 1. Main App Component (`src/App.tsx`)
- Layout principal
- Routing (si se requiere en el futuro)
- Providers globales

#### 2. SAT Form Component (`src/components/SatForm.tsx`)
```typescript
interface SatFormProps {
  onSubmit: (data: SatFormData) => void;
  loading: boolean;
}

interface SatFormData {
  certificate: File;
  privateKey: File;
  password: string;
  documentType?: string;
  downloadType?: string;
  startDate?: string;
  endDate?: string;
}
```

#### 3. Results Display Component (`src/components/ResultsDisplay.tsx`)
```typescript
interface ResultsDisplayProps {
  results: SatResponse | null;
  error: string | null;
}

interface SatResponse {
  success: boolean;
  data: {
    rfc: string;
    tokenValidUntil: string;
    tokenCreated: string;
    requestId?: string;
    status?: string;
    message?: string;
    packages?: string[];
  };
  dbId?: number;
}
```

#### 4. API Service (`src/services/satApi.ts`)
```typescript
class SatApiService {
  async authenticate(formData: FormData): Promise<SatResponse>
  private handleApiError(error: any): never
}
```

## Data Models

### Database Schema (Existing)
```sql
CREATE TABLE sat_consultas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rfc VARCHAR(13) NOT NULL,
    tipo_servicio VARCHAR(50) NOT NULL,
    token_valido_hasta DATETIME NOT NULL,
    token_creado DATETIME NOT NULL,
    id_solicitud VARCHAR(100),
    estado VARCHAR(50),
    mensaje TEXT,
    fecha_consulta DATETIME DEFAULT CURRENT_TIMESTAMP,
    certificado LONGTEXT,
    llave LONGTEXT,
    password VARCHAR(255)
);
```

### API Response Models

#### Success Response
```json
{
  "success": true,
  "data": {
    "rfc": "XAXX010101000",
    "tokenValidUntil": "2024-01-01 12:00:00",
    "tokenCreated": "2024-01-01 10:00:00",
    "requestId": "uuid-request-id",
    "status": "ACCEPTED",
    "message": "Consulta exitosa",
    "packages": ["package-id-1", "package-id-2"]
  },
  "dbId": 123
}
```

#### Error Response
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "La FIEL no es válida",
    "details": "Verifica que los archivos correspondan a una FIEL vigente"
  }
}
```

### TypeScript Interfaces

```typescript
// Frontend type definitions
interface SatFormData {
  certificate: File;
  privateKey: File;
  password: string;
  documentType: 'ingreso' | 'egreso';
  downloadType: 'received' | 'issued';
  startDate?: string;
  endDate?: string;
}

interface SatApiResponse {
  success: boolean;
  data?: SatResponseData;
  error?: ApiError;
  dbId?: number;
}

interface SatResponseData {
  rfc: string;
  tokenValidUntil: string;
  tokenCreated: string;
  requestId?: string;
  status?: string;
  message?: string;
  packages?: string[];
}

interface ApiError {
  code: string;
  message: string;
  details?: string;
}
```

## Error Handling

### Backend Error Handling

1. **Validation Errors (400)**
   - Archivos faltantes o inválidos
   - Parámetros requeridos faltantes
   - Formato de fecha inválido

2. **Authentication Errors (401)**
   - FIEL inválida
   - Contraseña incorrecta
   - Certificado expirado

3. **Server Errors (500)**
   - Errores de conexión a base de datos
   - Errores del servicio SAT
   - Errores de sistema de archivos

### Frontend Error Handling

1. **Network Errors**
   - Timeout de conexión
   - Backend no disponible
   - Errores de CORS

2. **Validation Errors**
   - Archivos no seleccionados
   - Formato de archivo incorrecto
   - Campos requeridos vacíos

3. **User Experience**
   - Loading states durante peticiones
   - Mensajes de error user-friendly
   - Retry mechanisms para errores temporales

## Testing Strategy

### Backend Testing

1. **Unit Tests**
   - SatService: Pruebas de lógica de autenticación
   - DatabaseService: Pruebas de operaciones CRUD
   - FileHandler: Pruebas de manejo de archivos

2. **Integration Tests**
   - API endpoints con datos reales
   - Conexión a base de datos de prueba
   - Integración con servicios SAT (mocked)

3. **Security Tests**
   - Validación de tipos de archivo
   - Sanitización de inputs
   - Manejo seguro de archivos temporales

### Frontend Testing

1. **Component Tests**
   - Renderizado de formularios
   - Validación de inputs
   - Manejo de estados de carga

2. **Integration Tests**
   - Comunicación con API
   - Flujo completo de usuario
   - Manejo de errores

3. **E2E Tests**
   - Flujo completo desde upload hasta resultados
   - Responsive design en diferentes dispositivos
   - Accesibilidad básica

### Test Data Strategy

- **Mock SAT Services**: Para pruebas sin dependencias externas
- **Test Database**: Base de datos separada para pruebas
- **Sample Files**: Certificados de prueba para validación
- **Error Scenarios**: Casos de prueba para diferentes tipos de errores

## Security Considerations

### Backend Security

1. **File Upload Security**
   - Validación estricta de tipos de archivo (.cer, .key)
   - Límites de tamaño de archivo
   - Sanitización de nombres de archivo
   - Almacenamiento temporal seguro

2. **Data Protection**
   - Encriptación de contraseñas en base de datos
   - Limpieza automática de archivos temporales
   - Logs de seguridad para accesos

3. **API Security**
   - Rate limiting para prevenir abuso
   - Validación de Content-Type
   - Headers de seguridad apropiados

### Frontend Security

1. **Input Validation**
   - Validación client-side antes del envío
   - Sanitización de datos de usuario
   - Prevención de XSS en resultados

2. **Communication Security**
   - HTTPS obligatorio en producción
   - Validación de certificados SSL
   - Timeout apropiados para peticiones

## Performance Considerations

### Backend Performance

1. **File Handling**
   - Streaming para archivos grandes
   - Limpieza automática de archivos temporales
   - Optimización de operaciones I/O

2. **Database Optimization**
   - Índices apropiados en tabla sat_consultas
   - Connection pooling si es necesario
   - Queries optimizadas

### Frontend Performance

1. **Bundle Optimization**
   - Code splitting con Vite
   - Tree shaking automático
   - Optimización de assets

2. **User Experience**
   - Loading states inmediatos
   - Debouncing en validaciones
   - Caching de resultados cuando apropiado

## Deployment Strategy

### Backend Deployment

- Mantener compatibilidad con servidor actual (Apache/Nginx)
- Estructura de directorios que no interfiera con código existente
- Variables de entorno para configuración de base de datos

### Frontend Deployment

- Build estático servido por servidor web
- Configuración de proxy para API calls
- Variables de entorno para URLs de API

### Migration Strategy

1. **Phase 1**: Desarrollar API manteniendo funcionalidad actual
2. **Phase 2**: Desarrollar frontend y probar integración
3. **Phase 3**: Deployment gradual con fallback a versión actual
4. **Phase 4**: Migración completa una vez validado el sistema