<?php
try {
    $conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=deber", "usuarioweb", "web123");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Agregar después de la línea 10 (después de la conexión a la base de datos)

// Función para registrar en bitácora
function registrarEnBitacora($conn, $usuario, $accion, $tabla, $registroId, $detalles, $datosAnteriores = null, $datosNuevos = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $sql = "INSERT INTO bitacora 
            (usuario, accion, tabla_afectada, registro_id, detalles, ip_usuario, datos_anteriores, datos_nuevos) 
            VALUES (:usuario, :accion, :tabla, :registroId, :detalles, :ip, :datosAnteriores, :datosNuevos)";
    
    $stmt = $conn->prepare($sql);

    $datosAnterioresJson = $datosAnteriores ? json_encode($datosAnteriores) : null;
    $datosNuevosJson = $datosNuevos ? json_encode($datosNuevos) : null;

    $stmt->execute([
        ':usuario' => $usuario,
        ':accion' => $accion,
        ':tabla' => $tabla,
        ':registroId' => $registroId,
        ':detalles' => $detalles,
        ':ip' => $ip,
        ':datosAnteriores' => $datosAnterioresJson,
        ':datosNuevos' => $datosNuevosJson
    ]);
}


// API para eliminar registro (REEMPLAZA tu código actual)
if (isset($_GET['api']) && $_GET['api'] == 'delete' && isset($_GET['table']) && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $tableName = $_GET['table'];
    $recordId = $_GET['id'];
    $idField = isset($_GET['field']) ? $_GET['field'] : 'id';
    $usuario = 'usuarioweb'; // Cambiar por el usuario actual si usas sesiones

    // Validar nombre de tabla y campo (evita SQL Injection)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $idField)) {
        echo json_encode(['error' => 'Nombre de tabla o campo inválido']);
        exit;
    }

    try {
        // Obtener datos antes de eliminar
        $selectStmt = $conn->prepare("SELECT * FROM $tableName WHERE $idField = :id");
        $selectStmt->execute([':id' => $recordId]);
        $datosAnteriores = $selectStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$datosAnteriores) {
            echo json_encode(['error' => 'No se encontró el registro a eliminar']);
            exit;
        }

        // Eliminar registro
        $deleteStmt = $conn->prepare("DELETE FROM $tableName WHERE $idField = :id");
        $deleteStmt->execute([':id' => $recordId]);

        if ($deleteStmt->rowCount() > 0) {
            // Registrar en bitácora
            registrarEnBitacora(
                $conn, 
                $usuario, 
                'ELIMINAR', 
                $tableName, 
                $recordId, 
                "Registro eliminado de la tabla $tableName",
                $datosAnteriores,
                null
            );
            echo json_encode(['success' => 'Registro eliminado correctamente']);
        } else {
            echo json_encode(['error' => 'No se encontró el registro a eliminar']);
        }

    } catch (Exception $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }

    exit;
}


// API para actualizar registro (REEMPLAZA tu código actual)
if (isset($_POST['api']) && $_POST['api'] == 'update' && isset($_POST['table']) && isset($_POST['id'])) {
    header('Content-Type: application/json');

    $tableName = $_POST['table'];
    $recordId = $_POST['id'];
    $idField = isset($_POST['field']) ? $_POST['field'] : 'id';
    $usuario = 'usuarioweb'; // Cambiar por sesión si usas autenticación

    // Validar tabla y campo
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $idField)) {
        echo json_encode(['error' => 'Nombre de tabla o campo inválido']);
        exit;
    }

    try {
        // Obtener datos antes de actualizar
        $selectStmt = $conn->prepare("SELECT * FROM $tableName WHERE $idField = :id");
        $selectStmt->execute([':id' => $recordId]);
        $datosAnteriores = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$datosAnteriores) {
            echo json_encode(['error' => 'Registro no encontrado']);
            exit;
        }

        // Construir dinámicamente los campos para el UPDATE
        $updateFields = [];
        $updateValues = [];
        $datosNuevos = [];

        foreach ($_POST as $key => $value) {
            if (!in_array($key, ['api', 'table', 'id', 'field'])) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) continue; // Validar campos

                $updateFields[] = "$key = :$key";
                $updateValues[":$key"] = $value;
                $datosNuevos[$key] = $value;
            }
        }

        if (empty($updateFields)) {
            echo json_encode(['error' => 'No hay campos para actualizar']);
            exit;
        }

        $updateValues[":id"] = $recordId;
        $sql = "UPDATE $tableName SET " . implode(', ', $updateFields) . " WHERE $idField = :id";

        $updateStmt = $conn->prepare($sql);
        $updateStmt->execute($updateValues);

        if ($updateStmt->rowCount() > 0) {
            // Registrar en bitácora
            registrarEnBitacora(
                $conn,
                $usuario,
                'ACTUALIZAR',
                $tableName,
                $recordId,
                "Registro actualizado en la tabla $tableName",
                $datosAnteriores,
                array_merge($datosAnteriores, $datosNuevos)
            );

            echo json_encode(['success' => 'Registro actualizado correctamente']);
        } else {
            echo json_encode(['error' => 'No se realizaron cambios o no se encontró el registro']);
        }

    } catch (Exception $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }

    exit;
}

// API para registrar consultas
if (isset($_POST['api']) && $_POST['api'] == 'log_consulta' && isset($_POST['tabla'])) {
    $tableName = $_POST['tabla'];
    $usuario = 'usuarioweb'; // o $_SESSION['usuario']

    try {
        registrarEnBitacora(
            $conn,
            $usuario,
            'CONSULTAR',
            $tableName,
            null,
            "Consulta realizada a la tabla $tableName",
            null,
            null
        );

        echo json_encode(['success' => 'Consulta registrada']);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error al registrar consulta: ' . $e->getMessage()]);
    }

    exit;
}


// API para el buscador de tablas (MANTÉN tu código actual)
if (isset($_GET['api']) && $_GET['api'] == 'table' && isset($_GET['name'])) {
    header('Content-Type: application/json');

    $tableName = $_GET['name'];
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;

    // Validar nombre de tabla
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        echo json_encode(['error' => 'Nombre de tabla inválido']);
        exit;
    }

    try {
        // Verificar si la tabla existe en PostgreSQL
        $stmt = $conn->prepare("SELECT to_regclass(:tableName) AS exists");
        $stmt->execute([':tableName' => $tableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result['exists']) {
            echo json_encode(['error' => "La tabla '$tableName' no existe"]);
            exit;
        }

        // Obtener total de registros
        $stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM $tableName");
        $stmtCount->execute();
        $total = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

        // Obtener registros paginados
        $stmtData = $conn->prepare("SELECT * FROM $tableName LIMIT :limit OFFSET :offset");
        $stmtData->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtData->execute();

        $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'tableName' => $tableName,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $rows
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error al consultar la tabla: ' . $e->getMessage()]);
    }

    exit;
}


// API para obtener bitácora (NUEVO - AGREGAR)
if (isset($_GET['api']) && $_GET['api'] == 'bitacora') {
    header('Content-Type: application/json');

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;

    $filtroUsuario = isset($_GET['usuario']) ? $_GET['usuario'] : '';
    $filtroAccion = isset($_GET['accion']) ? $_GET['accion'] : '';
    $filtroTabla = isset($_GET['tabla']) ? $_GET['tabla'] : '';

    try {
        // Construir consulta con filtros
        $whereConditions = [];
        $params = [];

        if (!empty($filtroUsuario)) {
            $whereConditions[] = "usuario ILIKE :usuario";
            $params[':usuario'] = "%$filtroUsuario%";
        }

        if (!empty($filtroAccion)) {
            $whereConditions[] = "accion = :accion";
            $params[':accion'] = $filtroAccion;
        }

        if (!empty($filtroTabla)) {
            $whereConditions[] = "tabla_afectada = :tabla";
            $params[':tabla'] = $filtroTabla;
        }

        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

        // Obtener total de registros
        $countSql = "SELECT COUNT(*) as total FROM bitacora $whereClause";
        $countStmt = $conn->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Obtener datos con paginación
        $dataSql = "SELECT * FROM bitacora $whereClause ORDER BY fecha_hora DESC LIMIT :limit OFFSET :offset";
        $dataStmt = $conn->prepare($dataSql);

        // Bind de parámetros dinámicos
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }

        $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $dataStmt->execute();

        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $rows
        ]);

    } catch (Exception $e) {
        echo json_encode(['error' => 'Error al consultar la bitácora: ' . $e->getMessage()]);
    }

    exit;
}

?>
<html lang="en">
    
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>🍳 El hueco, recetario</title>
        <link rel="stylesheet" href="css/pagina_inicio.css">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
        .buscador-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            margin: 20px 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .search-section {
            margin-bottom: 30px;
        }
        
        .search-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        
        .search-btn {
            padding: 12px 25px;
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }
        
        .suggestions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .suggestion-chip {
            padding: 6px 15px;
            background: rgba(0, 123, 255, 0.1);
            border: 1px solid rgba(0, 123, 255, 0.3);
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            color: #007bff;
        }
        
        .suggestion-chip:hover {
            background: rgba(0, 123, 255, 0.2);
            transform: translateY(-1px);
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
            color: #007bff;
        }
        
        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .table-info {
            background: linear-gradient(45deg, rgba(0, 123, 255, 0.1), rgba(0, 86, 179, 0.1));
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }
        
        .table-name {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .table-meta {
            color: #666;
            font-size: 14px;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            background: white;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .data-table th {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .data-table td {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s ease;
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 123, 255, 0.05);
        }
        
        .data-table tr:nth-child(even) {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .error-message {
            background: linear-gradient(45deg, rgba(220, 53, 69, 0.1), rgba(255, 193, 7, 0.1));
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #dc3545;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid #dc3545;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .page-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .page-btn:hover, .page-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        @media (max-width: 768px) {
            .search-container {
                flex-direction: column;
            }
            .search-input, .search-btn {
                width: 100%;
            }
        }
        /* Agregar estos estilos dentro de la etiqueta <style> existente */

.action-buttons {
    display: flex;
    gap: 5px;
    justify-content: center;
}

.btn-action {
    padding: 4px 8px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: bold;
    transition: all 0.3s ease;
    min-width: 60px;
}

.btn-edit {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
}

.btn-edit:hover {
    background: linear-gradient(45deg, #218838, #1aa179);
    transform: translateY(-1px);
}

.btn-delete {
    background: linear-gradient(45deg, #dc3545, #fd7e14);
    color: white;
}

.btn-delete:hover {
    background: linear-gradient(45deg, #c82333, #e8610c);
    transform: translateY(-1px);
}

/* Modal para editar */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.modal-title {
    margin: 0;
    color: #333;
    font-size: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #555;
}

.form-input {
    width: 100%;
    padding: 10px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-input:focus {
    outline: none;
    border-color: #007bff;
}

.modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 2px solid #f0f0f0;
}

.btn-save {
    background: linear-gradient(45deg, #007bff, #0056b3);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.btn-cancel {
    background: #6c757d;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
}
/* Badges para acciones */
.badge-consultar {
    background: linear-gradient(45deg, #17a2b8, #138496);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
}

.badge-actualizar {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
}

.badge-eliminar {
    background: linear-gradient(45deg, #dc3545, #fd7e14);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
}

/* Responsive para filtros */
@media (max-width: 768px) {
    .search-section div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
    </style>
    </head>
    <body>
        <header>
            <h1>🍳 El hueco, recetario</h1>
            <nav>
                <ul>
 
                    <a href="conexion.php" onclick="cerrarSesion(event)">Cerrar sesión</a>

                </ul>
            </nav>
        </header>

        <main>

            
            <section class="tarjetas" id="seccionTarjetas" style="display: block;">
                <div class="movimiento_tarjetas">
                    
                     <!-- Nueva tarjeta para el buscador de tablas -->
                <a href="#buscador" class="tarjeta1" onclick="mostrarBuscador()">                         
                    <h3>Explorar Base de Datos</h3>                         
                    <span class="icono">🗃️</span>                     
                </a>    
                
<!-- AGREGAR esta tarjeta dentro de la sección .movimiento_tarjetas -->
<a href="#bitacora" class="tarjeta3" onclick="mostrarBitacora()">
    <h3>Ver Bitácora</h3>
    <span class="icono">📋</span>
</a>
                </div>
            </section>
             <!-- Sección del buscador de tablas -->
        <section class="buscador-container" id="buscadorTablas" style="display: none;">
            <h2>🔍 Buscador de Tablas de Base de Datos</h2>
            
            <div class="search-section">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="Escribe el nombre de una tabla (ej: ventas, clientes, usuarios...)">
                    <button onclick="searchTable()" class="search-btn">Buscar Tabla</button>
                </div>
                
                <div class="suggestions">
                    <span class="suggestion-chip" onclick="quickSearch('usuarios')">usuarios</span>
                    <span class="suggestion-chip" onclick="quickSearch('ventas')">ventas</span>
                    <span class="suggestion-chip" onclick="quickSearch('productos')">productos</span>
                    <span class="suggestion-chip" onclick="quickSearch('categorias')">categorías</span>
                    <span class="suggestion-chip" onclick="quickSearch('clientes')">clientes</span>
                </div>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Cargando datos de la tabla...</p>
            </div>

            <div class="results-section" id="results">
                <div class="no-results">
                    <div style="font-size: 48px; margin-bottom: 20px;">📊</div>
                    <h3>Busca una tabla para ver sus datos</h3>
                    <p>Escribe el nombre de una tabla de tu base de datos para cargar todos sus registros</p>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button onclick="ocultarBuscador()" class="btn btn-secondary">Volver al Menú Principal</button>
            </div>
        </section>  
        <!-- AGREGAR después de la sección buscadorTablas -->
<section class="buscador-container" id="bitacoraSection" style="display: none;">
    <h2>📋 Bitácora del Sistema</h2>
    
    <div class="search-section">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <input type="text" id="filtroUsuario" class="search-input" placeholder="Filtrar por usuario">
            <select id="filtroAccion" class="search-input">
                <option value="">Todas las acciones</option>
                <option value="CONSULTAR">CONSULTAR</option>
                <option value="ACTUALIZAR">ACTUALIZAR</option>
                <option value="ELIMINAR">ELIMINAR</option>
            </select>
            <select id="filtroTabla" class="search-input">
                <option value="">Todas las tablas</option>
                <option value="ventas">ventas</option>
                <option value="clientes">clientes</option>
                <option value="usuarios">usuarios</option>
                <option value="productos">productos</option>
                <option value="categorias">categorias</option>
            </select>
            <button onclick="cargarBitacora()" class="search-btn">Filtrar</button>
        </div>
    </div>

    <div class="loading" id="loadingBitacora">
        <div class="spinner"></div>
        <p>Cargando bitácora...</p>
    </div>

    <div class="results-section" id="resultsBitacora">
        <div class="no-results">
            <div style="font-size: 48px; margin-bottom: 20px;">📋</div>
            <h3>Carga la bitácora para ver los registros</h3>
            <p>Aquí se mostrarán todas las acciones realizadas en el sistema</p>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 20px;">
        <button onclick="ocultarBitacora()" class="btn btn-secondary">Volver al Menú Principal</button>
    </div>
</section> 
        </main>

        
       
        <script src="js/script.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script>
        // Variables globales
let currentPage = 1;
let totalPages = 1;
let currentTable = '';
let currentEditData = {};
let currentBitacoraPage = 1;
let totalBitacoraPages = 1;

// ==================== FUNCIONES DE NAVEGACIÓN ====================

// Función para mostrar el buscador
function mostrarBuscador() {
    document.getElementById('seccionTarjetas').style.display = 'none';
    document.getElementById('buscadorTablas').style.display = 'block';
    document.getElementById('bitacoraSection').style.display = 'none';
}

// Función para ocultar el buscador
function ocultarBuscador() {
    document.getElementById('seccionTarjetas').style.display = 'block';
    document.getElementById('buscadorTablas').style.display = 'none';
    document.getElementById('bitacoraSection').style.display = 'none';
    // Limpiar resultados
    document.getElementById('results').innerHTML = `
        <div class="no-results">
            <div style="font-size: 48px; margin-bottom: 20px;">📊</div>
            <h3>Busca una tabla para ver sus datos</h3>
            <p>Escribe el nombre de una tabla de tu base de datos para cargar todos sus registros</p>
        </div>
    `;
}

// Función para mostrar la bitácora
function mostrarBitacora() {
    document.getElementById('seccionTarjetas').style.display = 'none';
    document.getElementById('buscadorTablas').style.display = 'none';
    document.getElementById('bitacoraSection').style.display = 'block';
    
    // Cargar bitácora automáticamente
    cargarBitacora();
}

// Función para ocultar la bitácora
function ocultarBitacora() {
    document.getElementById('seccionTarjetas').style.display = 'block';
    document.getElementById('bitacoraSection').style.display = 'none';
    document.getElementById('buscadorTablas').style.display = 'none';
}

// ==================== FUNCIONES DE TABLAS ====================

// Función para buscar tabla
async function searchTable() {
    const tableName = document.getElementById('searchInput').value.trim();
    if (!tableName) {
        alert('Por favor, ingresa el nombre de una tabla');
        return;
    }
    
    await loadTableData(tableName, 1);
}

// Función para búsqueda rápida
function quickSearch(tableName) {
    document.getElementById('searchInput').value = tableName;
    loadTableData(tableName, 1);
}

// Función principal para cargar datos de tabla
async function loadTableData(tableName, page = 1) {
    currentTable = tableName;
    currentPage = page;
    
    // Mostrar loading
    document.getElementById('loading').style.display = 'block';
    document.getElementById('results').innerHTML = '';

    try {
        // Hacer petición a la API PHP
        const response = await fetch(`?api=table&name=${tableName}&page=${page}&limit=50`);
        const data = await response.json();
        
        // Ocultar loading
        document.getElementById('loading').style.display = 'none';
        
        if (data.error) {
            showError(data.error);
        } else {
            // Mostrar resultados
            displayTableData(data, tableName);
            // Registrar consulta en bitácora
            registrarConsultaEnBitacora(tableName);
        }
        
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('loading').style.display = 'none';
        showError(`Error al cargar la tabla "${tableName}": ${error.message}`);
    }
}

// Función para mostrar datos de tabla
function displayTableData(data, tableName) {
    const resultsDiv = document.getElementById('results');
    
    if (!data.rows || data.rows.length === 0) {
        resultsDiv.innerHTML = `
            <div class="no-results">
                <div style="font-size: 48px; margin-bottom: 20px;">📭</div>
                <h3>No hay datos en la tabla "${tableName}"</h3>
                <p>La tabla existe pero no contiene registros</p>
            </div>
        `;
        return;
    }

    // Obtener columnas de la primera fila
    const columns = Object.keys(data.rows[0]);
    // Detectar la columna ID (buscar 'id', 'ID', o la primera columna)
    const idField = columns.find(col => col.toLowerCase() === 'id') || columns[0];
    
    // Crear HTML de la tabla
    let tableHTML = `
        <div class="table-info">
            <div class="table-name">Tabla: ${tableName}</div>
            <div class="table-meta">
                ${data.total} registros totales | ${columns.length} columnas | Página ${currentPage} de ${Math.ceil(data.total / 50)}
            </div>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        ${columns.map(col => `<th>${col}</th>`).join('')}
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
    `;

    // Agregar filas de datos
    data.rows.forEach(row => {
        const idValue = row[idField];
        tableHTML += '<tr>';
        
        // Agregar celdas de datos
        columns.forEach(col => {
            let value = row[col];
            if (value === null || value === undefined) {
                value = '<em style="color: #999;">NULL</em>';
            } else if (typeof value === 'string' && value.length > 100) {
                value = value.substring(0, 100) + '...';
            }
            tableHTML += `<td>${value}</td>`;
        });
        
        // Agregar botones de acción
        tableHTML += `
            <td>
                <div class="action-buttons">
                    <button class="btn-action btn-edit" onclick="editRecord('${tableName}', '${idValue}', '${idField}')">
                        ✏️ Editar
                    </button>
                    <button class="btn-action btn-delete" onclick="deleteRecord('${tableName}', '${idValue}', '${idField}')">
                        🗑️ Eliminar
                    </button>
                </div>
            </td>
        `;
        tableHTML += '</tr>';
    });

    tableHTML += `
                </tbody>
            </table>
        </div>
    `;

    // Agregar paginación si hay más de una página
    totalPages = Math.ceil(data.total / 50);
    if (totalPages > 1) {
        tableHTML += createPagination();
    }

    resultsDiv.innerHTML = tableHTML;
}

// Función para crear paginación
function createPagination() {
    let paginationHTML = '<div class="pagination">';
    
    // Botón anterior
    if (currentPage > 1) {
        paginationHTML += `<button class="page-btn" onclick="loadTableData('${currentTable}', ${currentPage - 1})">← Anterior</button>`;
    }
    
    // Números de página (mostrar hasta 5 páginas)
    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === currentPage ? 'active' : '';
        paginationHTML += `<button class="page-btn ${activeClass}" onclick="loadTableData('${currentTable}', ${i})">${i}</button>`;
    }
    
    // Botón siguiente
    if (currentPage < totalPages) {
        paginationHTML += `<button class="page-btn" onclick="loadTableData('${currentTable}', ${currentPage + 1})">Siguiente →</button>`;
    }
    
    paginationHTML += '</div>';
    return paginationHTML;
}

// ==================== FUNCIONES DE CRUD ====================

// Función para eliminar registro
async function deleteRecord(tableName, id, idField) {
    if (!confirm(`¿Estás seguro de que quieres eliminar este registro de la tabla "${tableName}"?`)) {
        return;
    }
    
    try {
        const response = await fetch(`?api=delete&table=${tableName}&id=${id}&field=${idField}`);
        const result = await response.json();
        
        if (result.success) {
            alert('Registro eliminado correctamente');
            // Recargar la tabla actual
            loadTableData(currentTable, currentPage);
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Error al eliminar el registro: ' + error.message);
    }
}

// Función para abrir modal de edición
async function editRecord(tableName, id, idField) {
    // Buscar los datos del registro en la tabla actual
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    let recordData = {};
    
    // Encontrar la fila correspondiente
    tableRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const headers = document.querySelectorAll('.data-table thead th');
        
        // Buscar por ID
        let rowId = null;
        for (let i = 0; i < headers.length - 1; i++) { // -1 para excluir la columna "Acciones"
            if (headers[i].textContent.toLowerCase() === idField.toLowerCase()) {
                rowId = cells[i].textContent.trim();
                break;
            }
        }
        
        if (rowId === id) {
            // Extraer todos los datos de esta fila
            for (let i = 0; i < headers.length - 1; i++) { // -1 para excluir "Acciones"
                const columnName = headers[i].textContent;
                let cellValue = cells[i].textContent.trim();
                
                // Manejar valores NULL
                if (cellValue === 'NULL') {
                    cellValue = '';
                }
                
                recordData[columnName] = cellValue;
            }
        }
    });
    
    currentEditData = {
        tableName: tableName,
        id: id,
        idField: idField,
        data: recordData
    };
    
    showEditModal(recordData, tableName);
}

// Función para mostrar el modal de edición
function showEditModal(data, tableName) {
    const modalHTML = `
        <div class="modal-overlay" id="editModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Editar registro - ${tableName}</h3>
                </div>
                <form id="editForm">
                    ${Object.keys(data).map(key => `
                        <div class="form-group">
                            <label class="form-label">${key}:</label>
                            <input type="text" class="form-input" name="${key}" value="${data[key]}" 
                                   ${key.toLowerCase() === currentEditData.idField.toLowerCase() ? 'readonly' : ''}>
                        </div>
                    `).join('')}
                    <div class="modal-buttons">
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancelar</button>
                        <button type="button" class="btn-save" onclick="saveRecord()">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Función para cerrar el modal
function closeEditModal() {
    const modal = document.getElementById('editModal');
    if (modal) {
        modal.remove();
    }
}

// Función para guardar los cambios
async function saveRecord() {
    const form = document.getElementById('editForm');
    const formData = new FormData(form);
    
    // Agregar datos adicionales
    formData.append('api', 'update');
    formData.append('table', currentEditData.tableName);
    formData.append('id', currentEditData.id);
    formData.append('field', currentEditData.idField);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Registro actualizado correctamente');
            closeEditModal();
            // Recargar la tabla actual
            loadTableData(currentTable, currentPage);
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Error al actualizar el registro: ' + error.message);
    }
}

// ==================== FUNCIONES DE BITÁCORA ====================

// Función para cargar bitácora
async function cargarBitacora(page = 1) {
    currentBitacoraPage = page;
    
    const usuario = document.getElementById('filtroUsuario').value.trim();
    const accion = document.getElementById('filtroAccion').value;
    const tabla = document.getElementById('filtroTabla').value;
    
    // Mostrar loading
    document.getElementById('loadingBitacora').style.display = 'block';
    document.getElementById('resultsBitacora').innerHTML = '';

    try {
        // Construir URL con filtros
        let url = `?api=bitacora&page=${page}&limit=50`;
        if (usuario) url += `&usuario=${encodeURIComponent(usuario)}`;
        if (accion) url += `&accion=${encodeURIComponent(accion)}`;
        if (tabla) url += `&tabla=${encodeURIComponent(tabla)}`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        // Ocultar loading
        document.getElementById('loadingBitacora').style.display = 'none';
        
        if (data.error) {
            mostrarErrorBitacora(data.error);
        } else {
            mostrarDatosBitacora(data);
        }
        
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('loadingBitacora').style.display = 'none';
        mostrarErrorBitacora(`Error al cargar la bitácora: ${error.message}`);
    }
}

// Función para mostrar datos de bitácora
function mostrarDatosBitacora(data) {
    const resultsDiv = document.getElementById('resultsBitacora');
    
    if (!data.rows || data.rows.length === 0) {
        resultsDiv.innerHTML = `
            <div class="no-results">
                <div style="font-size: 48px; margin-bottom: 20px;">📭</div>
                <h3>No se encontraron registros</h3>
                <p>No hay registros de bitácora con los filtros aplicados</p>
            </div>
        `;
        return;
    }

    let tableHTML = `
        <div class="table-info">
            <div class="table-name">Bitácora del Sistema</div>
            <div class="table-meta">
                ${data.total} registros totales | Página ${currentBitacoraPage} de ${Math.ceil(data.total / 50)}
            </div>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Tabla</th>
                        <th>Registro ID</th>
                        <th>Detalles</th>
                        <th>IP</th>
                        <th>Fecha/Hora</th>
                        <th>Ver Datos</th>
                    </tr>
                </thead>
                <tbody>
    `;

    data.rows.forEach(row => {
        const fecha = new Date(row.fecha_hora).toLocaleString('es-ES');
        
        tableHTML += `
            <tr>
                <td>${row.id}</td>
                <td>${row.usuario}</td>
                <td>
                    <span class="badge-${row.accion.toLowerCase()}">${row.accion}</span>
                </td>
                <td>${row.tabla_afectada || '-'}</td>
                <td>${row.registro_id || '-'}</td>
                <td title="${row.detalles}">${row.detalles ? (row.detalles.length > 50 ? row.detalles.substring(0, 50) + '...' : row.detalles) : '-'}</td>
                <td>${row.ip_usuario}</td>
                <td>${fecha}</td>
                <td>
                    ${(row.datos_anteriores || row.datos_nuevos) ? 
                        `<button class="btn-action btn-edit" onclick="verDetallesBitacora(${row.id}, '${row.accion}', ${JSON.stringify(row.datos_anteriores).replace(/"/g, '&quot;')}, ${JSON.stringify(row.datos_nuevos).replace(/"/g, '&quot;')})">
                            👁️ Ver
                        </button>` : 
                        '-'
                    }
                </td>
            </tr>
        `;
    });

    tableHTML += `
                </tbody>
            </table>
        </div>
    `;

    // Agregar paginación
    totalBitacoraPages = Math.ceil(data.total / 50);
    if (totalBitacoraPages > 1) {
        tableHTML += crearPaginacionBitacora();
    }

    resultsDiv.innerHTML = tableHTML;
}

// Función para crear paginación de bitácora
function crearPaginacionBitacora() {
    let paginationHTML = '<div class="pagination">';
    
    if (currentBitacoraPage > 1) {
        paginationHTML += `<button class="page-btn" onclick="cargarBitacora(${currentBitacoraPage - 1})">← Anterior</button>`;
    }
    
    const startPage = Math.max(1, currentBitacoraPage - 2);
    const endPage = Math.min(totalBitacoraPages, currentBitacoraPage + 2);
    
    for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === currentBitacoraPage ? 'active' : '';
        paginationHTML += `<button class="page-btn ${activeClass}" onclick="cargarBitacora(${i})">${i}</button>`;
    }
    
    if (currentBitacoraPage < totalBitacoraPages) {
        paginationHTML += `<button class="page-btn" onclick="cargarBitacora(${currentBitacoraPage + 1})">Siguiente →</button>`;
    }
    
    paginationHTML += '</div>';
    return paginationHTML;
}

// Función para ver detalles de bitácora
function verDetallesBitacora(id, accion, datosAnteriores, datosNuevos) {
    let contenido = `<h4>Detalles del Registro #${id}</h4><br>`;
    
    if (accion === 'ELIMINAR' && datosAnteriores) {
        contenido += '<h5>Datos eliminados:</h5>';
        contenido += '<div style="background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">';
        contenido += JSON.stringify(datosAnteriores, null, 2).replace(/\n/g, '<br>').replace(/ /g, '&nbsp;');
        contenido += '</div>';
    } else if (accion === 'ACTUALIZAR' && datosAnteriores && datosNuevos) {
        contenido += '<h5>Datos anteriores:</h5>';
        contenido += '<div style="background: #fff3cd; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px; margin-bottom: 10px;">';
        contenido += JSON.stringify(datosAnteriores, null, 2).replace(/\n/g, '<br>').replace(/ /g, '&nbsp;');
        contenido += '</div>';
        
        contenido += '<h5>Datos nuevos:</h5>';
        contenido += '<div style="background: #d1edff; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">';
        contenido += JSON.stringify(datosNuevos, null, 2).replace(/\n/g, '<br>').replace(/ /g, '&nbsp;');
        contenido += '</div>';
    }
    
    const modalHTML = `
        <div class="modal-overlay" id="detalleBitacoraModal">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3 class="modal-title">Detalles de Bitácora</h3>
                </div>
                <div style="max-height: 400px; overflow-y: auto;">
                    ${contenido}
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="cerrarDetalleBitacora()">Cerrar</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

// Función para cerrar modal de detalles
function cerrarDetalleBitacora() {
    const modal = document.getElementById('detalleBitacoraModal');
    if (modal) {
        modal.remove();
    }
}

// Función para registrar consultas en bitácora
async function registrarConsultaEnBitacora(tableName) {
    try {
        await fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `api=log_consulta&tabla=${tableName}`
        });
    } catch (error) {
        console.log('Error al registrar consulta:', error);
    }
}

// ==================== FUNCIONES DE ERROR ====================

// Función para mostrar errores
function showError(message) {
    const resultsDiv = document.getElementById('results');
    resultsDiv.innerHTML = `
        <div class="error-message">
            <strong>Error:</strong> ${message}
            <br><br>
            <small>Verifica que:</small>
            <ul style="margin-top: 10px; margin-left: 20px;">
                <li>El nombre de la tabla sea correcto</li>
                <li>La tabla exista en tu base de datos</li>
                <li>Tengas permisos para acceder a la tabla</li>
            </ul>
        </div>
    `;
}

// Función para mostrar errores de bitácora
function mostrarErrorBitacora(message) {
    const resultsDiv = document.getElementById('resultsBitacora');
    resultsDiv.innerHTML = `
        <div class="error-message">
            <strong>Error:</strong> ${message}
        </div>
    `;
}

// ==================== EVENT LISTENERS ====================

// Permitir búsqueda con Enter y configurar eventos al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Mostrar tarjetas al cargar
    document.getElementById('seccionTarjetas').style.display = 'block';
    
    // Configurar búsqueda con Enter
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchTable();
            }
        });
    }
});

// Cerrar modales al hacer clic fuera de ellos
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('modal-overlay')) {
        if (e.target.id === 'editModal') {
            closeEditModal();
        } else if (e.target.id === 'detalleBitacoraModal') {
            cerrarDetalleBitacora();
        }
    }
});
    </script>
    </body>
</html>