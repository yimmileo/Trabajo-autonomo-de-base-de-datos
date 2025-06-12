<?php
session_start();

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=deber", "usuarioweb", "web123");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Función para verificar permisos según el rol
function verificarPermiso($accion, $tabla = null) {
    if (!isset($_SESSION['usuario_rol'])) {
        return false;
    }
    
    $rol = $_SESSION['usuario_rol'];
    
    switch ($rol) {
        case 'admin':
            return true;
            
        case 'empleado':
            $tablasPermitidas = ['productos', 'clientes', 'ventas', 'categorias', 'usuarios'];
            
            if ($accion === 'SELECT' || $accion === 'UPDATE') {
                return !$tabla || in_array($tabla, $tablasPermitidas);
            }
            
            if ($accion === 'INSERT' || $accion === 'DELETE') {
                return false;
            }
            
            if ($tabla === 'bitacora') {
                return false;
            }
            
            return false;
            
        case 'cliente':
            $tablasLectura = ['productos', 'categorias'];
            
            if ($accion === 'SELECT') {
                return !$tabla || in_array($tabla, $tablasLectura);
            }
            
            return false;
            
        default:
            return false;
    }
}

// Función para registrar en bitácora
function registrarEnBitacora($conn, $usuario, $accion, $tabla, $registroId, $detalles, $datosAnteriores = null, $datosNuevos = null) {
    try {
        $ip = 'unknown';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $sql = "INSERT INTO bitacora 
                (usuario, accion, tabla_afectada, registro_id, detalles, ip_usuario, 
                 datos_anteriores, datos_nuevos, fecha_hora) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);

        $datosAnterioresJson = $datosAnteriores ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE) : null;
        $datosNuevosJson = $datosNuevos ? json_encode($datosNuevos, JSON_UNESCAPED_UNICODE) : null;

        $stmt->execute([
            $usuario, $accion, $tabla, $registroId, $detalles, $ip,
            $datosAnterioresJson, $datosNuevosJson
        ]);
        
        error_log("Bitácora insertada: Usuario=$usuario, Acción=$accion, Tabla=$tabla");
        
    } catch (Exception $e) {
        error_log("Error al registrar en bitácora: " . $e->getMessage());
        echo "<!-- Error bitácora: " . $e->getMessage() . " -->";
    }
}

// API para logout
if (isset($_POST['api']) && $_POST['api'] == 'logout') {
    header('Content-Type: application/json');
    
    if (isset($_SESSION['usuario_id'])) {
        $tiempoSesion = '';
        if (isset($_SESSION['login_time'])) {
            $inicio = new DateTime($_SESSION['login_time']);
            $fin = new DateTime();
            $diferencia = $inicio->diff($fin);
            $tiempoSesion = " - Tiempo de sesión: " . $diferencia->format('%H:%I:%S');
        }
        
        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'LOGOUT', 'sistema', null, 
                          'Usuario cerró sesión' . $tiempoSesion);
    }
    
    session_destroy();
    echo json_encode(['success' => true, 'redirect' => 'login.php']);
    exit;
}

// API MODIFICADA para obtener datos con paginación
if (isset($_GET['api']) && $_GET['api'] == 'get_data') {
    header('Content-Type: application/json');
    
    $tabla = $_GET['tabla'] ?? '';
    $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
    $registrosPorPagina = 5; // Fijo en 5 registros por página
    
    if (!verificarPermiso('SELECT', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos para ver esta tabla']);
        exit;
    }
    
    try {
        // Calcular offset
        $offset = ($pagina - 1) * $registrosPorPagina;
        
        // Contar total de registros
        $sqlCount = "SELECT COUNT(*) as total FROM " . $tabla;
        $stmtCount = $conn->prepare($sqlCount);
        $stmtCount->execute();
        $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calcular total de páginas
        $totalPaginas = ceil($totalRegistros / $registrosPorPagina);
        
        // Obtener datos paginados
        $sql = "SELECT * FROM " . $tabla . " ORDER BY 1 LIMIT " . $registrosPorPagina . " OFFSET " . $offset;
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $descripcion = "Consultó página $pagina de la tabla $tabla (" . count($datos) . " de $totalRegistros registros)";
        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'SELECT', $tabla, null, $descripcion);
        
        echo json_encode([
            'success' => true, 
            'data' => $datos,
            'paginacion' => [
                'pagina_actual' => $pagina,
                'total_paginas' => $totalPaginas,
                'total_registros' => $totalRegistros,
                'registros_por_pagina' => $registrosPorPagina
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error al obtener datos: ' . $e->getMessage()]);
    }
    exit;
}

// API para obtener estructura de tabla
if (isset($_GET['api']) && $_GET['api'] == 'get_columns') {
    header('Content-Type: application/json');
    
    $tabla = $_GET['tabla'] ?? '';
    
    if (!verificarPermiso('SELECT', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos']);
        exit;
    }
    
    try {
        $sql = "SELECT column_name, data_type, is_nullable 
                FROM information_schema.columns 
                WHERE table_name = ? 
                ORDER BY ordinal_position";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$tabla]);
        $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'columns' => $columnas]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// API para insertar datos
if (isset($_POST['api']) && $_POST['api'] == 'insert_data') {
    header('Content-Type: application/json');
    
    $tabla = $_POST['tabla'] ?? '';
    $datosJson = $_POST['datos'] ?? '';
    
    $datos = json_decode($datosJson, true);
    
    if (!$datos) {
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        exit;
    }
    
    if (!verificarPermiso('INSERT', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos para insertar en esta tabla']);
        exit;
    }
    
    try {
        $datos = array_filter($datos, function($value) {
            return $value !== '' && $value !== null;
        });
        
        if (empty($datos)) {
            echo json_encode(['success' => false, 'error' => 'No hay datos válidos para insertar']);
            exit;
        }
        
        $campos = array_keys($datos);
        $placeholders = array_fill(0, count($campos), '?');
        
        $sql = "INSERT INTO " . $tabla . " (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_values($datos));
        
        $registroId = $conn->lastInsertId();
        
        $descripcion = "Insertó nuevo registro en tabla $tabla con ID $registroId";
        if ($tabla == 'productos' && isset($datos['nombre'])) {
            $descripcion .= " - Producto: " . $datos['nombre'];
        } elseif ($tabla == 'clientes' && isset($datos['nombre'])) {
            $descripcion .= " - Cliente: " . $datos['nombre'];
        } elseif ($tabla == 'usuarios' && isset($datos['usuario'])) {
            $descripcion .= " - Usuario: " . $datos['usuario'];
        }

        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'INSERT', $tabla, $registroId, 
                          $descripcion, null, $datos);
        
        echo json_encode(['success' => true, 'message' => 'Registro insertado correctamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error al insertar: ' . $e->getMessage()]);
    }
    exit;
}

// API para actualizar datos
if (isset($_POST['api']) && $_POST['api'] == 'update_data') {
    header('Content-Type: application/json');
    
    $tabla = $_POST['tabla'] ?? '';
    $datosJson = $_POST['datos'] ?? '';
    $id = $_POST['id'] ?? '';
    $campoId = $_POST['campo_id'] ?? '';
    
    $datos = json_decode($datosJson, true);
    
    if (!$datos) {
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        exit;
    }
    
    if (!verificarPermiso('UPDATE', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos para actualizar esta tabla']);
        exit;
    }
    
    try {
        $sqlAnterior = "SELECT * FROM " . $tabla . " WHERE " . $campoId . " = ?";
        $stmtAnterior = $conn->prepare($sqlAnterior);
        $stmtAnterior->execute([$id]);
        $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);
        
        $datosLimpios = [];
        foreach($datos as $key => $value) {
            if ($value !== '' && $value !== null && $key !== $campoId) {
                $datosLimpios[$key] = $value;
            }
        }
        
        if (empty($datosLimpios)) {
            echo json_encode(['success' => false, 'error' => 'No hay datos válidos para actualizar']);
            exit;
        }
        
        $campos = array_keys($datosLimpios);
        $setClause = implode(' = ?, ', $campos) . ' = ?';
        
        $sql = "UPDATE " . $tabla . " SET " . $setClause . " WHERE " . $campoId . " = ?";
        $valores = array_values($datosLimpios);
        $valores[] = $id;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($valores);
        
        $descripcion = "Actualizó registro ID $id en tabla $tabla";
        $cambios = [];
        foreach($datosLimpios as $campo => $valorNuevo) {
            $valorAnterior = $datosAnteriores[$campo] ?? 'NULL';
            if ($valorAnterior != $valorNuevo) {
                $cambios[] = "cambió $campo de '$valorAnterior' a '$valorNuevo'";
            }
        }
        if (!empty($cambios)) {
            $descripcion .= " - " . implode(', ', $cambios);
        }

        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'UPDATE', $tabla, $id, 
                          $descripcion, $datosAnteriores, $datosLimpios);
        
        echo json_encode(['success' => true, 'message' => 'Registro actualizado correctamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// API para eliminar datos
if (isset($_POST['api']) && $_POST['api'] == 'delete_data') {
    header('Content-Type: application/json');
    
    $tabla = $_POST['tabla'] ?? '';
    $id = $_POST['id'] ?? '';
    $campoId = $_POST['campo_id'] ?? '';
    
    if (!verificarPermiso('DELETE', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos para eliminar de esta tabla']);
        exit;
    }
    
    try {
        $sqlAnterior = "SELECT * FROM " . $tabla . " WHERE " . $campoId . " = ?";
        $stmtAnterior = $conn->prepare($sqlAnterior);
        $stmtAnterior->execute([$id]);
        $datosAnteriores = $stmtAnterior->fetch(PDO::FETCH_ASSOC);
        
        $sql = "DELETE FROM " . $tabla . " WHERE " . $campoId . " = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        
        $descripcion = "Eliminó registro ID $id de tabla $tabla";
        if ($tabla == 'productos' && isset($datosAnteriores['nombre'])) {
            $descripcion .= " - Producto: " . $datosAnteriores['nombre'];
        } elseif ($tabla == 'clientes' && isset($datosAnteriores['nombre'])) {
            $descripcion .= " - Cliente: " . $datosAnteriores['nombre'];
        } elseif ($tabla == 'usuarios' && isset($datosAnteriores['usuario'])) {
            $descripcion .= " - Usuario: " . $datosAnteriores['usuario'];
        }

        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'DELETE', $tabla, $id, 
                          $descripcion, $datosAnteriores, null);
        
        echo json_encode(['success' => true, 'message' => 'Registro eliminado correctamente']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error al eliminar: ' . $e->getMessage()]);
    }
    exit;
}

// API para obtener un registro específico
if (isset($_GET['api']) && $_GET['api'] == 'get_record') {
    header('Content-Type: application/json');
    
    $tabla = $_GET['tabla'] ?? '';
    $id = $_GET['id'] ?? '';
    $campoId = $_GET['campo_id'] ?? '';
    
    if (!verificarPermiso('SELECT', $tabla)) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos']);
        exit;
    }
    
    try {
        $sql = "SELECT * FROM " . $tabla . " WHERE " . $campoId . " = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $registro]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍳 El hueco, recetario</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        header {
            background: linear-gradient(45deg, #FF6B6B, #4ECDC4);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .user-info {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 3px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .user-details h3 {
            color: #333;
            margin-bottom: 8px;
        }

        .user-details p {
            color: #666;
            margin: 4px 0;
        }

        .badge {
            background: #007bff;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge.admin { background: #dc3545; }
        .badge.empleado { background: #ffc107; color: #000; }
        .badge.cliente { background: #28a745; }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .main-content {
            padding: 30px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }

        .table-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .table-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            text-align: center;
        }

        .table-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .table-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        .data-section {
            display: none;
            margin-top: 30px;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .table-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
        }

        .close-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .close-btn:hover {
            background: #5a6268;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }

        .data-table th {
            background: #343a40;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }

        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        /* ESTILOS PARA PAGINACIÓN */
        .pagination-container {
            background: #f8f9fa;
            padding: 15px 20px;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: #666;
            font-size: 0.9em;
        }

        .pagination-controls {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .pagination-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: #0056b3;
        }

        .pagination-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: #28a745;
        }

        .pagination-btn.active:hover {
            background: #218838;
        }

        @media (max-width: 768px) {
            .user-info {
                flex-direction: column;
                text-align: center;
            }

            .logout-btn {
                margin-top: 15px;
            }

            .table-buttons {
                grid-template-columns: 1fr;
            }

            .data-table {
                font-size: 0.8em;
            }

            .pagination-container {
                flex-direction: column;
                gap: 10px;
            }
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn-add {
            background: #28a745;
            color: white;
        }

        .btn-add:hover {
            background: #218838;
        }

        .btn-edit {
            background: #ffc107;
            color: #000;
            padding: 5px 10px;
            font-size: 0.8em;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            font-size: 0.8em;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .btn-disabled {
            background: #6c757d !important;
            cursor: not-allowed !important;
        }

        .btn-disabled:hover {
            background: #6c757d !important;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }

        .modal-title {
            font-size: 1.5em;
            color: #333;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #666;
        }

        .modal-close:hover {
            color: #000;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 5px;
            font-size: 16px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn-save {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-save:hover {
            background: #0056b3;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border: 1px solid #c3e6cb;
        }

        .action-cell {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🍳 El hueco, recetario</h1>
            <p>Sistema de Gestión de Recetas</p>
        </header>

        <div class="user-info">
            <div class="user-details">
                <h3>Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>!</h3>
                <p><strong>Rol:</strong> <span class="badge <?php echo $_SESSION['usuario_rol']; ?>"><?php echo ucfirst($_SESSION['usuario_rol']); ?></span></p>
                <p><strong>Sesión iniciada:</strong> <?php echo $_SESSION['login_time']; ?></p>
            </div>
            <button onclick="logout()" class="logout-btn">Cerrar Sesión</button>
        </div>
        <a href="reportes.php" class="nav-btn" style="background: #28a745; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-weight: bold;">📊 Reportes Avanzados</a>

        <div class="main-content">
            <div class="section">
                <h2>📊 Tablas del Sistema</h2>
                <div class="table-buttons">
                    <?php if (verificarPermiso('SELECT', 'productos')): ?>
                        <button class="table-btn" onclick="loadTable('productos')">🥘 Productos</button>
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('SELECT', 'categorias')): ?>
                        <button class="table-btn" onclick="loadTable('categorias')">📋 Categorías</button>
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('SELECT', 'clientes')): ?>
                        <button class="table-btn" onclick="loadTable('clientes')">👥 Clientes</button>
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('SELECT', 'ventas')): ?>
                        <button class="table-btn" onclick="loadTable('ventas')">💰 Ventas</button>
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('SELECT', 'usuarios')): ?>
                        <button class="table-btn" onclick="loadTable('usuarios')">🚚 Usuarios</button>
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('SELECT', 'bitacora')): ?>
                        <button class="table-btn" onclick="loadTable('bitacora')">📝 Bitácora</button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="dataSection" class="data-section">
                <div class="table-container">
                    <div class="table-header">
                        <div class="table-title" id="tableTitle">Datos de la Tabla</div>
                        <button class="close-btn" onclick="closeTable()">✕ Cerrar</button>
                    </div>
                    <div id="tableContent">
                        <div class="loading">Cargando datos...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    

    <script>
        let currentTable = '';
        let currentColumns = [];
        let currentPage = 1;
        let totalPages = 1;

        function logout() {
            if (confirm('¿Está seguro que desea cerrar sesión?')) {
                const formData = new FormData();
                formData.append('api', 'logout');
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect || 'login.php';
                    }
                })
                .catch(() => {
                    window.location.href = 'login.php';
                });
            }
        }

        function loadTable(tableName, page = 1) {
            currentTable = tableName;
            currentPage = page;
            const dataSection = document.getElementById('dataSection');
            const tableTitle = document.getElementById('tableTitle');
            const tableContent = document.getElementById('tableContent');
            
            dataSection.style.display = 'block';
            tableTitle.textContent = `📊 Datos de ${tableName.charAt(0).toUpperCase() + tableName.slice(1)}`;
            tableContent.innerHTML = '<div class="loading">Cargando datos...</div>';
            
            dataSection.scrollIntoView({ behavior: 'smooth' });
            
            // Cargar estructura de columnas primero
            fetch(`index.php?api=get_columns&tabla=${tableName}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentColumns = data.columns;
                        loadTableData(tableName, page);
                    } else {
                        tableContent.innerHTML = `<div class="error">Error: ${data.error}</div>`;
                    }
                })
                .catch(error => {
                    tableContent.innerHTML = `<div class="error">Error de conexión: ${error.message}</div>`;
                });
        }

        function loadTableData(tableName, page = 1) {
            const tableContent = document.getElementById('tableContent');
            
            fetch(`index.php?api=get_data&tabla=${tableName}&pagina=${page}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayTable(data.data, tableName, data.paginacion);
                    } else {
                        tableContent.innerHTML = `<div class="error">Error: ${data.error}</div>`;
                    }
                })
                .catch(error => {
                    tableContent.innerHTML = `<div class="error">Error de conexión: ${error.message}</div>`;
                });
        }

        function displayTable(data, tableName, paginacion) {
            const tableContent = document.getElementById('tableContent');
            
            // Actualizar variables de paginación
            currentPage = paginacion.pagina_actual;
            totalPages = paginacion.total_paginas;
            
            // Botones de acción
            let actionButtons = '<div class="action-buttons">';
            
            const userRole = '<?php echo $_SESSION['usuario_rol']; ?>';
            
            if (userRole === 'admin') {
                actionButtons += `<button class="action-btn btn-add" onclick="showAddModal('${tableName}')">➕ Agregar Nuevo</button>`;
            }
            
            actionButtons += '</div>';
            
            if (!data || data.length === 0) {
                tableContent.innerHTML = actionButtons + '<div class="loading">No hay datos en esta tabla.</div>' + createPaginationControls(paginacion);
                return;
            }
            
            // Crear tabla HTML
            let html = actionButtons + '<table class="data-table"><thead><tr>';
            
            // Headers
            const columns = Object.keys(data[0]);
            columns.forEach(col => {
                html += `<th>${col.charAt(0).toUpperCase() + col.slice(1)}</th>`;
            });
            
            // Columna de acciones si hay permisos
            if (userRole === 'admin' || userRole === 'empleado') {
                html += '<th>Acciones</th>';
            }
            
            html += '</tr></thead><tbody>';
            
            // Filas de datos
            data.forEach(row => {
                html += '<tr>';
                columns.forEach(col => {
                    let value = row[col];
                    if (value === null) value = '<em>null</em>';
                    else if (typeof value === 'object') value = JSON.stringify(value);
                    else if (typeof value === 'string' && value.length > 50) value = value.substring(0, 50) + '...';
                    html += `<td>${value}</td>`;
                });
                
                // Botones de acción por fila
                if (userRole === 'admin' || userRole === 'empleado') {
                    html += '<td class="action-cell">';
                    
                    const firstKey = columns[0];
                    const recordId = row[firstKey];
                    
                    if (userRole === 'admin' || userRole === 'empleado') {
                        html += `<button class="action-btn btn-edit" onclick="showEditModal('${tableName}', '${recordId}', '${firstKey}')">✏️ Editar</button> `;
                    }
                    
                    if (userRole === 'admin') {
                        html += `<button class="action-btn btn-delete" onclick="deleteRecord('${tableName}', '${recordId}', '${firstKey}')">🗑️ Eliminar</button>`;
                    }
                    
                    html += '</td>';
                }
                
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            // Agregar controles de paginación
            html += createPaginationControls(paginacion);
            
            // Agregar modal para formularios
            html += createModal();
            
            tableContent.innerHTML = html;
        }

        function createPaginationControls(paginacion) {
            const { pagina_actual, total_paginas, total_registros, registros_por_pagina } = paginacion;
            
            let html = '<div class="pagination-container">';
            
            // Información de paginación
            const inicio = ((pagina_actual - 1) * registros_por_pagina) + 1;
            const fin = Math.min(pagina_actual * registros_por_pagina, total_registros);
            
            html += `<div class="pagination-info">
                Mostrando ${inicio} - ${fin} de ${total_registros} registros
            </div>`;
            
            // Controles de paginación
            html += '<div class="pagination-controls">';
            
            // Botón Primera página
            html += `<button class="pagination-btn" onclick="loadTable('${currentTable}', 1)" ${pagina_actual === 1 ? 'disabled' : ''}>
                ⏮️ Primera
            </button>`;
            
            // Botón Anterior
            html += `<button class="pagination-btn" onclick="loadTable('${currentTable}', ${pagina_actual - 1})" ${pagina_actual === 1 ? 'disabled' : ''}>
                ⬅️ Anterior
            </button>`;
            
            // Números de página
            const startPage = Math.max(1, pagina_actual - 2);
            const endPage = Math.min(total_paginas, pagina_actual + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagina_actual ? 'active' : '';
                html += `<button class="pagination-btn ${activeClass}" onclick="loadTable('${currentTable}', ${i})">
                    ${i}
                </button>`;
            }
            
            // Botón Siguiente
            html += `<button class="pagination-btn" onclick="loadTable('${currentTable}', ${pagina_actual + 1})" ${pagina_actual === total_paginas ? 'disabled' : ''}>
                Siguiente ➡️
            </button>`;
            
            // Botón Última página
            html += `<button class="pagination-btn" onclick="loadTable('${currentTable}', ${total_paginas})" ${pagina_actual === total_paginas ? 'disabled' : ''}>
                Última ⏭️
            </button>`;
            
            html += '</div></div>';
            
            return html;
        }

        function createModal() {
            return `
                <div id="crudModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title" id="modalTitle"></h3>
                            <button class="modal-close" onclick="closeModal()">&times;</button>
                        </div>
                        <div id="modalBody"></div>
                    </div>
                </div>
            `;
        }

        function showAddModal(tableName) {
            const modal = document.getElementById('crudModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            
            modalTitle.textContent = `Agregar nuevo registro a ${tableName}`;
            
            let formHtml = '<form id="crudForm">';
            
            currentColumns.forEach(col => {
                if (col.column_name.toLowerCase().includes('id') && 
                    col.data_type === 'integer' && 
                    currentColumns.indexOf(col) === 0) {
                    return;
                }
                
                formHtml += `
                    <div class="form-group">
                        <label for="${col.column_name}">${col.column_name.charAt(0).toUpperCase() + col.column_name.slice(1)}:</label>
                        ${getInputField(col)}
                    </div>
                `;
            });
            
            formHtml += `
                <div class="form-actions">
                    <button type="button" class="btn-save" onclick="saveRecord('${tableName}', 'add')">💾 Guardar</button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">❌ Cancelar</button>
                </div>
            </form>`;
            
            modalBody.innerHTML = formHtml;
            modal.style.display = 'block';
        }

        function showEditModal(tableName, recordId, fieldId) {
            const modal = document.getElementById('crudModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            
            modalTitle.textContent = `Editar registro de ${tableName}`;
            modalBody.innerHTML = '<div class="loading">Cargando datos...</div>';
            modal.style.display = 'block';
            
            fetch(`index.php?api=get_record&tabla=${tableName}&id=${recordId}&campo_id=${fieldId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let formHtml = '<form id="crudForm">';
                        
                        currentColumns.forEach(col => {
                            const value = data.data[col.column_name] || '';
                            
                            formHtml += `
                                <div class="form-group">
                                    <label for="${col.column_name}">${col.column_name.charAt(0).toUpperCase() + col.column_name.slice(1)}:</label>
                                    ${getInputField(col, value, col.column_name === fieldId)}
                                </div>
                            `;
                        });
                        
                        formHtml += `
                            <input type="hidden" id="recordId" value="${recordId}">
                            <input type="hidden" id="fieldId" value="${fieldId}">
                            <div class="form-actions">
                                <button type="button" class="btn-save" onclick="saveRecord('${tableName}', 'edit')">💾 Actualizar</button>
                                <button type="button" class="btn-cancel" onclick="closeModal()">❌ Cancelar</button>
                            </div>
                        </form>`;
                        
                        modalBody.innerHTML = formHtml;
                    } else {
                        modalBody.innerHTML = `<div class="error">Error: ${data.error}</div>`;
                    }
                })
                .catch(error => {
                    modalBody.innerHTML = `<div class="error">Error: ${error.message}</div>`;
                });
        }

        function getInputField(column, value = '', isReadOnly = false) {
            const name = column.column_name;
            const type = column.data_type.toLowerCase();
            const readOnlyAttr = isReadOnly ? 'readonly' : '';
            const required = column.is_nullable === 'NO' && !isReadOnly ? 'required' : '';
            
            const escapedValue = String(value).replace(/"/g, '&quot;');
            
            if (type.includes('text') || type.includes('varchar') && value.length > 50) {
                return `<textarea name="${name}" id="${name}" ${readOnlyAttr} ${required}>${escapedValue}</textarea>`;
            } else if (type.includes('int') || type.includes('numeric') || type.includes('decimal') || type.includes('float')) {
                return `<input type="number" name="${name}" id="${name}" value="${escapedValue}" ${readOnlyAttr} ${required} step="any">`;
            } else if (type.includes('date')) {
                let dateValue = '';
                if (value && value !== '') {
                    const date = new Date(value);
                    if (!isNaN(date.getTime())) {
                        dateValue = date.toISOString().split('T')[0];
                    }
                }
                return `<input type="date" name="${name}" id="${name}" value="${dateValue}" ${readOnlyAttr} ${required}>`;
            } else if (type.includes('timestamp') || type.includes('datetime')) {
                let datetimeValue = '';
                if (value && value !== '') {
                    const date = new Date(value);
                    if (!isNaN(date.getTime())) {
                        datetimeValue = date.toISOString().slice(0, 16);
                    }
                }
                return `<input type="datetime-local" name="${name}" id="${name}" value="${datetimeValue}" ${readOnlyAttr} ${required}>`;
            } else if (type.includes('bool')) {
                const checked = (value === true || value === 't' || value === '1' || value === 1) ? 'checked' : '';
                const disabled = isReadOnly ? 'disabled' : '';
                return `<input type="checkbox" name="${name}" id="${name}" ${checked} ${disabled}>`;
            } else if (type.includes('email')) {
                return `<input type="email" name="${name}" id="${name}" value="${escapedValue}" ${readOnlyAttr} ${required}>`;
            } else {
                return `<input type="text" name="${name}" id="${name}" value="${escapedValue}" ${readOnlyAttr} ${required}>`;
            }
        }

        function saveRecord(tableName, action) {
            const form = document.getElementById('crudForm');
            const formData = new FormData();
            
            formData.append('api', action === 'add' ? 'insert_data' : 'update_data');
            formData.append('tabla', tableName);
            
            const datos = {};
            
            currentColumns.forEach(col => {
                const input = document.getElementById(col.column_name);
                if (input) {
                    if (input.type === 'checkbox') {
                        datos[col.column_name] = input.checked ? '1' : '0';
                    } else if (input.type === 'number') {
                        if (input.value !== '') {
                            datos[col.column_name] = input.value;
                        }
                    } else if (input.value !== '') {
                        datos[col.column_name] = input.value;
                    }
                }
            });
            
            if (Object.keys(datos).length === 0) {
                showMessage('Debe llenar al menos un campo', 'error');
                return;
            }
            
            formData.append('datos', JSON.stringify(datos));
            
            if (action === 'edit') {
                const recordId = document.getElementById('recordId');
                const fieldId = document.getElementById('fieldId');
                
                if (!recordId || !fieldId) {
                    showMessage('Error: No se encontraron los identificadores del registro', 'error');
                    return;
                }
                
                formData.append('id', recordId.value);
                formData.append('campo_id', fieldId.value);
            }
            
            const saveBtn = document.querySelector('.btn-save');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = '⏳ Guardando...';
            saveBtn.disabled = true;
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('La respuesta del servidor no es JSON válida');
                }
                return response.json();
            })
            .then(data => {
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
                
                if (data.success) {
                    closeModal();
                    showMessage(data.message, 'success');
                    // Recargar la página actual después de guardar
                    loadTableData(tableName, currentPage);
                } else {
                    showMessage(data.error, 'error');
                }
            })
            .catch(error => {
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
                
                console.error('Error completo:', error);
                showMessage('Error de conexión: ' + error.message, 'error');
            });
        }

        function deleteRecord(tableName, recordId, fieldId) {
            if (confirm('¿Está seguro que desea eliminar este registro? Esta acción no se puede deshacer.')) {
                const formData = new FormData();
                formData.append('api', 'delete_data');
                formData.append('tabla', tableName);
                formData.append('id', recordId);
                formData.append('campo_id', fieldId);
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        // Recargar la página actual después de eliminar
                        loadTableData(tableName, currentPage);
                    } else {
                        showMessage(data.error, 'error');
                    }
                })
                .catch(error => {
                    showMessage('Error de conexión: ' + error.message, 'error');
                });
            }
        }

        function closeModal() {
            document.getElementById('crudModal').style.display = 'none';
        }

        function showMessage(message, type) {
            const messageDiv = document.createElement('div');
            messageDiv.className = type === 'success' ? 'success-message' : 'error';
            messageDiv.textContent = message;
            
            const tableContent = document.getElementById('tableContent');
            tableContent.insertBefore(messageDiv, tableContent.firstChild);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }

        function closeTable() {
            document.getElementById('dataSection').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('crudModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        console.log('Usuario actual:', '<?php echo $_SESSION['usuario_nombre'] ?? 'No definido'; ?>');
        console.log('Rol actual:', '<?php echo $_SESSION['usuario_rol'] ?? 'No definido'; ?>');
    </script>
</body>
</html>