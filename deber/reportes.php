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
            if ($accion === 'REPORTE') {
                return true; // Empleados pueden ver reportes
            }
            return false;
            
        case 'cliente':
            if ($accion === 'REPORTE' && $tabla === 'productos') {
                return true; // Clientes solo pueden ver reportes de productos
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
        
    } catch (Exception $e) {
        error_log("Error al registrar en bitácora: " . $e->getMessage());
    }
}



// API para obtener reporte de actividad de usuarios
if (isset($_GET['api']) && $_GET['api'] == 'reporte_actividad_usuarios') {
    header('Content-Type: application/json');
    
    if (!verificarPermiso('REPORTE', 'bitacora')) {
        echo json_encode(['success' => false, 'error' => 'No tiene permisos para ver este reporte']);
        exit;
    }
    
    try {
        // Consulta con joins para obtener actividad de usuarios con detalles
        $sql = "
            SELECT 
                u.nombre_usuario,
                u.rol,
                b.accion,
                b.tabla_afectada,
                COUNT(*) AS cantidad_operaciones,
                MIN(b.fecha_hora) AS primera_operacion,
                MAX(b.fecha_hora) AS ultima_operacion
            FROM 
                bitacora b
            JOIN 
                usuarios u ON b.usuario = u.nombre_usuario
            GROUP BY 
                u.nombre_usuario, u.rol, b.accion, b.tabla_afectada
            ORDER BY 
                u.nombre_usuario, cantidad_operaciones DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Consulta adicional para totales por usuario
        $sqlTotales = "
            SELECT 
                u.nombre_usuario,
                u.rol,
                COUNT(*) AS total_operaciones,
                COUNT(DISTINCT b.accion) AS tipos_operaciones,
                COUNT(DISTINCT b.tabla_afectada) AS tablas_afectadas,
                MIN(b.fecha_hora) AS primera_actividad,
                MAX(b.fecha_hora) AS ultima_actividad
            FROM 
                bitacora b
            JOIN 
                usuarios u ON b.usuario = u.nombre_usuario
            GROUP BY 
                u.nombre_usuario, u.rol
            ORDER BY 
                total_operaciones DESC
        ";
        
        $stmtTotales = $conn->prepare($sqlTotales);
        $stmtTotales->execute();
        $totalesPorUsuario = $stmtTotales->fetchAll(PDO::FETCH_ASSOC);
        
        $descripcion = "Consultó reporte de actividad de usuarios";
        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'REPORTE', 'bitacora', null, $descripcion);
        
        echo json_encode([
            'success' => true, 
            'data' => $datos,
            'totales_usuario' => $totalesPorUsuario
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error al obtener datos: ' . $e->getMessage()]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍳 Reportes - El hueco, recetario</title>
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

        .nav-buttons {
            display: flex;
            gap: 10px;
        }

        .nav-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }

        .nav-btn:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }

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

        .report-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .report-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .report-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .report-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        .report-btn-icon {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .report-section {
            display: none;
            margin-top: 30px;
        }

        .report-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .report-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
        }

        .report-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn:hover {
            background: #5a6268;
        }

        .action-btn.export {
            background: #28a745;
        }

        .action-btn.export:hover {
            background: #218838;
        }

        .action-btn.print {
            background: #17a2b8;
        }

        .action-btn.print:hover {
            background: #138496;
        }

        .action-btn.close {
            background: #dc3545;
        }

        .action-btn.close:hover {
            background: #c82333;
        }

        .report-content {
            padding: 20px;
        }

        .report-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .summary-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .summary-item {
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }

        .summary-value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }

        .summary-label {
            color: #666;
            font-size: 14px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.9em;
        }

        .report-table th {
            background: #343a40;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            position: sticky;
            top: 0;
        }

        .report-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .report-table tr:hover {
            background: #f8f9fa;
        }

        .report-table .number {
            text-align: right;
        }

        .report-table .date {
            white-space: nowrap;
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

        .chart-container {
            height: 300px;
            margin: 20px 0;
        }

        @media (max-width: 768px) {
            .user-info {
                flex-direction: column;
                text-align: center;
            }

            .nav-buttons {
                margin-top: 15px;
            }

            .report-buttons {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .report-table {
                font-size: 0.8em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🍳 El hueco, recetario</h1>
            <p>Sistema de Reportes Avanzados</p>
        </header>

        <div class="user-info">
            <div class="user-details">
                <h3>Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?>!</h3>
                <p><strong>Rol:</strong> <span class="badge <?php echo $_SESSION['usuario_rol']; ?>"><?php echo ucfirst($_SESSION['usuario_rol']); ?></span></p>
                <p><strong>Sesión iniciada:</strong> <?php echo $_SESSION['login_time']; ?></p>
            </div>
            <div class="nav-buttons">
                <a href="index.php" class="nav-btn">🏠 Volver al Sistema</a>
                <button onclick="logout()" class="logout-btn">Cerrar Sesión</button>
            </div>
        </div>

        <div class="main-content">
            <div class="section">
                <h2>📊 Reportes Disponibles</h2>
                <div class="report-buttons">
                    <?php if (verificarPermiso('REPORTE')): ?>
                        
                    <?php endif; ?>
                    
                    <?php if (verificarPermiso('REPORTE', 'bitacora')): ?>
                        <button class="report-btn" onclick="loadReport('actividad_usuarios')">
                            <span class="report-btn-icon">👥</span>
                            Actividad de Usuarios
                            <span>Análisis de operaciones por usuario en el sistema</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="reportSection" class="report-section">
                <div class="report-container">
                    <div class="report-header">
                        <div class="report-title" id="reportTitle">Título del Reporte</div>
                        <div class="report-actions">
                            <button class="action-btn export" onclick="exportToCSV()">
                                📥 Exportar CSV
                            </button>
                            <button class="action-btn print" onclick="printReport()">
                                🖨️ Imprimir
                            </button>
                            <button class="action-btn close" onclick="closeReport()">
                                ✕ Cerrar
                            </button>
                        </div>
                    </div>
                    <div id="reportContent" class="report-content">
                        <div class="loading">Cargando reporte...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentReport = '';
        let reportData = null;

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

        function loadReport(reportType) {
            currentReport = reportType;
            const reportSection = document.getElementById('reportSection');
            const reportTitle = document.getElementById('reportTitle');
            const reportContent = document.getElementById('reportContent');
            
            reportSection.style.display = 'block';
            
            if (reportType === 'ventas_categoria') {
                reportTitle.textContent = '📈 Reporte de Ventas por Categoría';
            } else if (reportType === 'actividad_usuarios') {
                reportTitle.textContent = '👥 Reporte de Actividad de Usuarios';
            }
            
            reportContent.innerHTML = '<div class="loading">Cargando datos del reporte...</div>';
            
            // Scroll hacia el reporte
            reportSection.scrollIntoView({ behavior: 'smooth' });
            
            // Cargar datos del reporte
            let apiEndpoint = '';
            if (reportType === 'ventas_categoria') {
                apiEndpoint = 'reporte_ventas_categoria';
            } else if (reportType === 'actividad_usuarios') {
                apiEndpoint = 'reporte_actividad_usuarios';
            }
            
            fetch(`reportes.php?api=${apiEndpoint}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        reportData = data;
                        displayReport(reportType, data);
                    } else {
                        reportContent.innerHTML = `<div class="error">Error: ${data.error}</div>`;
                    }
                })
                .catch(error => {
                    reportContent.innerHTML = `<div class="error">Error de conexión: ${error.message}</div>`;
                });
        }

        function displayReport(reportType, data) {
            const reportContent = document.getElementById('reportContent');
            
            if (reportType === 'ventas_categoria') {
                displayVentasCategoriaReport(data, reportContent);
            } else if (reportType === 'actividad_usuarios') {
                displayActividadUsuariosReport(data, reportContent);
            }
        }

        function displayVentasCategoriaReport(data, container) {
            if (!data.totales_categoria || data.totales_categoria.length === 0) {
                container.innerHTML = '<div class="error">No hay datos disponibles para este reporte.</div>';
                return;
            }
            
            // Calcular totales generales
            const totalVentas = data.totales_categoria.reduce((sum, item) => sum + parseFloat(item.total_ventas), 0);
            const totalCantidad = data.totales_categoria.reduce((sum, item) => sum + parseInt(item.cantidad_ventas), 0);
            
            // Crear resumen
            let html = `
                <div class="report-summary">
                    <div class="summary-title">Resumen de Ventas por Categoría</div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-value">${totalCantidad}</div>
                            <div class="summary-label">Total de Ventas</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">$${totalVentas.toFixed(2)}</div>
                            <div class="summary-label">Monto Total</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">${data.totales_categoria.length}</div>
                            <div class="summary-label">Categorías</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">$${(totalVentas / totalCantidad).toFixed(2)}</div>
                            <div class="summary-label">Promedio por Venta</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Tabla de totales por categoría
            html += `
                <h3>Ventas por Categoría</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th class="number">Cantidad de Ventas</th>
                            <th class="number">Total Ventas ($)</th>
                            <th class="number">% del Total</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            data.totales_categoria.forEach(categoria => {
                const porcentaje = (parseFloat(categoria.total_ventas) / totalVentas * 100).toFixed(2);
                html += `
                    <tr>
                        <td>${categoria.categoria}</td>
                        <td class="number">${categoria.cantidad_ventas}</td>
                        <td class="number">$${parseFloat(categoria.total_ventas).toFixed(2)}</td>
                        <td class="number">${porcentaje}%</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            // Tabla detallada por producto
            html += `
                <h3 style="margin-top: 30px;">Detalle de Ventas por Producto</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Producto</th>
                            <th class="number">Cantidad</th>
                            <th class="number">Total ($)</th>
                            <th class="number">Promedio ($)</th>
                            <th class="date">Primera Venta</th>
                            <th class="date">Última Venta</th>
                            <th class="number">Clientes</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            let currentCategoria = '';
            
            data.data.forEach(item => {
                // Agregar separación visual entre categorías
                const categoriaClass = item.categoria !== currentCategoria ? 'new-category' : '';
                currentCategoria = item.categoria;
                
                html += `
                    <tr class="${categoriaClass}">
                        <td>${item.categoria}</td>
                        <td>${item.producto}</td>
                        <td class="number">${item.cantidad_ventas}</td>
                        <td class="number">$${parseFloat(item.total_ventas).toFixed(2)}</td>
                        <td class="number">$${parseFloat(item.promedio_venta).toFixed(2)}</td>
                        <td class="date">${formatDate(item.primera_venta)}</td>
                        <td class="date">${formatDate(item.ultima_venta)}</td>
                        <td class="number">${item.clientes_distintos}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }

        function displayActividadUsuariosReport(data, container) {
            if (!data.totales_usuario || data.totales_usuario.length === 0) {
                container.innerHTML = '<div class="error">No hay datos disponibles para este reporte.</div>';
                return;
            }
            
            // Calcular totales generales
            const totalOperaciones = data.totales_usuario.reduce((sum, item) => sum + parseInt(item.total_operaciones), 0);
            const usuariosActivos = data.totales_usuario.length;
            
            // Crear resumen
            let html = `
                <div class="report-summary">
                    <div class="summary-title">Resumen de Actividad de Usuarios</div>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-value">${totalOperaciones}</div>
                            <div class="summary-label">Total de Operaciones</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">${usuariosActivos}</div>
                            <div class="summary-label">Usuarios Activos</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">${(totalOperaciones / usuariosActivos).toFixed(0)}</div>
                            <div class="summary-label">Promedio por Usuario</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value">${getUniqueCount(data.data, 'tabla_afectada')}</div>
                            <div class="summary-label">Tablas Afectadas</div>
                        </div>
                    </div>
                </div>
            `;
            
            // Tabla de totales por usuario
            html += `
                <h3>Actividad por Usuario</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th class="number">Total Operaciones</th>
                            <th class="number">Tipos de Operaciones</th>
                            <th class="number">Tablas Afectadas</th>
                            <th class="date">Primera Actividad</th>
                            <th class="date">Última Actividad</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            data.totales_usuario.forEach(usuario => {
                html += `
                    <tr>
                        <td>${usuario.nombre_usuario}</td>
                        <td>${usuario.rol}</td>
                        <td class="number">${usuario.total_operaciones}</td>
                        <td class="number">${usuario.tipos_operaciones}</td>
                        <td class="number">${usuario.tablas_afectadas}</td>
                        <td class="date">${formatDate(usuario.primera_actividad)}</td>
                        <td class="date">${formatDate(usuario.ultima_actividad)}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            // Tabla detallada por operación
            html += `
                <h3 style="margin-top: 30px;">Detalle de Operaciones por Usuario</h3>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Acción</th>
                            <th>Tabla</th>
                            <th class="number">Cantidad</th>
                            <th class="date">Primera Operación</th>
                            <th class="date">Última Operación</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            let currentUsuario = '';
            
            data.data.forEach(item => {
                // Agregar separación visual entre usuarios
                const usuarioClass = item.nombre_usuario !== currentUsuario ? 'new-user' : '';
                currentUsuario = item.nombre_usuario;
                
                html += `
                    <tr class="${usuarioClass}">
                        <td>${item.nombre_usuario}</td>
                        <td>${item.rol}</td>
                        <td>${item.accion}</td>
                        <td>${item.tabla_afectada}</td>
                        <td class="number">${item.cantidad_operaciones}</td>
                        <td class="date">${formatDate(item.primera_operacion)}</td>
                        <td class="date">${formatDate(item.ultima_operacion)}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            container.innerHTML = html;
        }

        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            return date.toLocaleString();
        }

        function getUniqueCount(array, property) {
            return new Set(array.map(item => item[property])).size;
        }

        function closeReport() {
            document.getElementById('reportSection').style.display = 'none';
            currentReport = '';
            reportData = null;
        }

        function exportToCSV() {
            if (!reportData) return;
            
            let csvContent = '';
            let filename = '';
            
            if (currentReport === 'ventas_categoria') {
                filename = 'reporte_ventas_por_categoria.csv';
                
                // Encabezados
                csvContent = 'Categoría,Producto,Cantidad Ventas,Total Ventas,Promedio Venta,Primera Venta,Última Venta,Clientes Distintos\n';
                
                // Datos
                reportData.data.forEach(item => {
                    csvContent += `"${item.categoria}","${item.producto}",${item.cantidad_ventas},${item.total_ventas},${item.promedio_venta},"${item.primera_venta}","${item.ultima_venta}",${item.clientes_distintos}\n`;
                });
            } else if (currentReport === 'actividad_usuarios') {
                filename = 'reporte_actividad_usuarios.csv';
                
                // Encabezados
                csvContent = 'Usuario,Rol,Acción,Tabla,Cantidad Operaciones,Primera Operación,Última Operación\n';
                
                // Datos
                reportData.data.forEach(item => {
                    csvContent += `"${item.nombre_usuario}","${item.rol}","${item.accion}","${item.tabla_afectada}",${item.cantidad_operaciones},"${item.primera_operacion}","${item.ultima_operacion}"\n`;
                });
            }
            
            // Crear y descargar el archivo CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function printReport() {
            window.print();
        }
    </script>
</body>
</html>