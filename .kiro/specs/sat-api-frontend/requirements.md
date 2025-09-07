# Requirements Document

## Introduction

Este proyecto consiste en separar la funcionalidad actual de `sat1.php` en dos componentes independientes: un backend API en PHP que maneje la lógica de autenticación con el SAT y almacenamiento en base de datos, y un frontend moderno en React/TypeScript con Tailwind CSS que proporcione una interfaz de usuario responsive para interactuar con el backend.

## Requirements

### Requirement 1

**User Story:** Como desarrollador, quiero un backend API en PHP que procese archivos de certificado FIEL (.cer/.key), RFC y contraseña, para que pueda ser consumido desde cualquier frontend.

#### Acceptance Criteria

1. WHEN se envía una petición POST al endpoint `/api/sat/authenticate` con archivos .cer, .key, contraseña y parámetros opcionales THEN el sistema SHALL procesar la autenticación con el SAT
2. WHEN la autenticación es exitosa THEN el sistema SHALL guardar los datos en la base de datos MySQL existente
3. WHEN se proporcionan fechas de inicio y fin THEN el sistema SHALL realizar consultas adicionales al SAT
4. WHEN ocurre un error THEN el sistema SHALL retornar un mensaje de error estructurado en formato JSON
5. IF los archivos de certificado son inválidos THEN el sistema SHALL retornar un error de validación
6. WHEN se completa cualquier operación THEN el sistema SHALL limpiar los archivos temporales

### Requirement 2

**User Story:** Como usuario final, quiero una interfaz web moderna y responsive para subir mis certificados FIEL y realizar consultas al SAT, para que pueda acceder al servicio desde cualquier dispositivo.

#### Acceptance Criteria

1. WHEN accedo a la aplicación frontend THEN el sistema SHALL mostrar un formulario responsive construido con Tailwind CSS
2. WHEN selecciono archivos .cer y .key THEN el sistema SHALL validar que sean del tipo correcto antes del envío
3. WHEN ingreso mi contraseña THEN el sistema SHALL mostrar el campo como tipo password
4. WHEN selecciono fechas opcionales THEN el sistema SHALL proporcionar selectores de fecha intuitivos
5. WHEN envío el formulario THEN el sistema SHALL mostrar un indicador de carga durante el procesamiento
6. WHEN recibo una respuesta del backend THEN el sistema SHALL mostrar los resultados de manera clara y estructurada
7. IF ocurre un error THEN el sistema SHALL mostrar mensajes de error user-friendly

### Requirement 3

**User Story:** Como administrador del sistema, quiero que el backend mantenga la compatibilidad con la base de datos existente, para que no se pierdan datos ni se requieran migraciones complejas.

#### Acceptance Criteria

1. WHEN se guarden datos en la base de datos THEN el sistema SHALL usar la misma estructura de tabla `sat_consultas` existente
2. WHEN se conecte a la base de datos THEN el sistema SHALL usar las mismas credenciales y configuración actual
3. WHEN se almacenen certificados THEN el sistema SHALL guardar el contenido completo de los archivos .cer y .key
4. WHEN se registre una consulta THEN el sistema SHALL incluir todos los campos requeridos: rfc, tipo_servicio, token_valido_hasta, token_creado, id_solicitud, estado, mensaje, fecha_consulta, certificado, llave, password

### Requirement 4

**User Story:** Como desarrollador frontend, quiero una API REST bien estructurada con respuestas JSON consistentes, para que pueda integrar fácilmente el frontend con el backend.

#### Acceptance Criteria

1. WHEN se realiza una petición al API THEN el sistema SHALL retornar respuestas en formato JSON
2. WHEN la operación es exitosa THEN el sistema SHALL retornar un status code 200 con los datos de respuesta
3. WHEN ocurre un error de validación THEN el sistema SHALL retornar status code 400 con detalles del error
4. WHEN ocurre un error del servidor THEN el sistema SHALL retornar status code 500 con mensaje de error
5. WHEN se sube un archivo THEN el sistema SHALL aceptar multipart/form-data
6. WHEN se retornan datos del SAT THEN el sistema SHALL incluir información de autenticación, consulta y verificación en la respuesta

### Requirement 5

**User Story:** Como usuario, quiero que el frontend sea desarrollado con tecnologías modernas (React, TypeScript, Vite, Tailwind CSS), para que tenga una experiencia de usuario rápida y moderna.

#### Acceptance Criteria

1. WHEN se construya el proyecto THEN el sistema SHALL usar Vite como bundler para desarrollo y build
2. WHEN se escriba código frontend THEN el sistema SHALL usar TypeScript para type safety
3. WHEN se diseñe la interfaz THEN el sistema SHALL usar Tailwind CSS para estilos responsive
4. WHEN se maneje el estado THEN el sistema SHALL usar React hooks apropiados
5. WHEN se realicen peticiones HTTP THEN el sistema SHALL usar fetch API o axios para comunicación con el backend
6. WHEN se muestren resultados THEN el sistema SHALL formatear la información de manera legible y organizada

### Requirement 6

**User Story:** Como usuario, quiero que la aplicación maneje errores de manera elegante y proporcione feedback claro, para que pueda entender qué está sucediendo en cada momento.

#### Acceptance Criteria

1. WHEN se está procesando una petición THEN el sistema SHALL mostrar indicadores de carga apropiados
2. WHEN ocurre un error de red THEN el sistema SHALL mostrar un mensaje explicativo al usuario
3. WHEN los archivos son inválidos THEN el sistema SHALL mostrar mensajes de validación específicos
4. WHEN la autenticación falla THEN el sistema SHALL mostrar el motivo del fallo de manera comprensible
5. WHEN se completa una operación exitosa THEN el sistema SHALL mostrar confirmación y resultados detallados
6. IF el backend no está disponible THEN el sistema SHALL mostrar un mensaje de error de conectividad