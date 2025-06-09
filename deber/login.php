<?php
session_start();

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
            return true; // Admin tiene todos los permisos
            
        case 'empleado':
            // Empleado puede hacer CRUD en tablas específicas
            $tablasPermitidas = ['productos', 'clientes', 'ventas', 'categorias', 'proveedores'];
            
            if ($accion === 'SELECT' || $accion === 'INSERT' || $accion === 'UPDATE' || $accion === 'DELETE') {
                return !$tabla || in_array($tabla, $tablasPermitidas);
            }
            
            // No puede ver bitácora
            if ($tabla === 'bitacora') {
                return false;
            }
            
            return false;
            
        case 'cliente':
            // Cliente solo puede hacer SELECT en ciertas tablas
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $navegador = obtenerNavegador($userAgent);
        $nombreMaquina = gethostname();

        $sql = "INSERT INTO bitacora 
                (usuario, accion, tabla_afectada, registro_id, detalles, ip_usuario, 
                 navegador, nombre_maquina, datos_anteriores, datos_nuevos, fecha_hora) 
                VALUES (:usuario, :accion, :tabla, :registroId, :detalles, :ip, 
                        :navegador, :nombreMaquina, :datosAnteriores, :datosNuevos, NOW())";
        
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
            ':navegador' => $navegador,
            ':nombreMaquina' => $nombreMaquina,
            ':datosAnteriores' => $datosAnterioresJson,
            ':datosNuevos' => $datosNuevosJson
        ]);
    } catch (Exception $e) {
        error_log("Error al registrar en bitácora: " . $e->getMessage());
    }
}

// Función para detectar el navegador
function obtenerNavegador($userAgent) {
    if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
    if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'Safari') !== false) return 'Safari';
    if (strpos($userAgent, 'Edge') !== false) return 'Edge';
    if (strpos($userAgent, 'Opera') !== false) return 'Opera';
    return 'Desconocido';
}

// Si ya está autenticado, redirigir al index
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// API para login
if (isset($_POST['api']) && $_POST['api'] == 'login') {
    header('Content-Type: application/json');
    
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';
    
    error_log("Intento de login - Usuario: $usuario");
    
    if (empty($usuario) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Usuario y contraseña son requeridos']);
        exit;
    }
    
    try {
        // Consulta a la tabla usuarios
        $sql = "SELECT id, nombre_usuario, correo, contrasena, rol FROM usuarios WHERE nombre_usuario = :usuario";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario' => $usuario]);
        $usuarioData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Datos encontrados: " . json_encode($usuarioData));
        
        if (!$usuarioData) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        
        // Verificar la contraseña
        $passwordValid = false;
        
        // Primero intentar con password_verify (contraseñas hasheadas)
        if (password_verify($password, $usuarioData['contrasena'])) {
            $passwordValid = true;
            error_log("Contraseña verificada con hash");
        } 
        // Si no funciona, intentar comparación directa (texto plano)
        else if ($password === $usuarioData['contrasena']) {
            $passwordValid = true;
            error_log("Contraseña verificada en texto plano");
        }
        
        if (!$passwordValid) {
            error_log("Contraseña inválida para usuario: $usuario");
            echo json_encode(['success' => false, 'error' => 'Credenciales inválidas']);
            exit;
        }
        
        error_log("Login exitoso para usuario: $usuario con rol: " . $usuarioData['rol']);
        
        // Login exitoso - crear sesión
        $_SESSION['usuario_id'] = $usuarioData['id'];
        $_SESSION['usuario_nombre'] = $usuarioData['nombre_usuario'];
        $_SESSION['usuario_rol'] = $usuarioData['rol'];
        $_SESSION['login_time'] = date('Y-m-d H:i:s');
        
        // Registrar inicio de sesión en bitácora
        registrarEnBitacora($conn, $usuario, 'LOGIN', 'sistema', null, 'Usuario inició sesión');
        
        echo json_encode([
            'success' => true,
            'usuario' => $usuarioData['nombre_usuario'],
            'rol' => $usuarioData['rol'],
            'redirect' => 'index.php' // Añadir la URL de redirección
        ]);
        
    } catch (Exception $e) {
        error_log("Error en login: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error en el sistema: ' . $e->getMessage()]);
    }
    
    exit;
}

// API para logout
if (isset($_POST['api']) && $_POST['api'] == 'logout') {
    header('Content-Type: application/json');
    
    if (isset($_SESSION['usuario_id'])) {
        registrarEnBitacora($conn, $_SESSION['usuario_nombre'], 'LOGOUT', 'sistema', null, 'Usuario cerró sesión');
    }
    
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Sesión cerrada correctamente']);
    exit;
}

// API para verificar sesión actual
if (isset($_GET['api']) && $_GET['api'] == 'session') {
    header('Content-Type: application/json');
    
    if (isset($_SESSION['usuario_id'])) {
        echo json_encode([
            'authenticated' => true,
            'usuario' => $_SESSION['usuario_nombre'],
            'rol' => $_SESSION['usuario_rol'],
            'login_time' => $_SESSION['login_time']
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍳Iniciar Sesión</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <header>
        <h1>🍳 El hueco, recetario</h1>
    </header>
    
    <main>
        <div class="formulario">
            <h1>Iniciar Sesión</h1>
            
            <!-- Contenedor para alertas -->
            <div id="alertContainer"></div>
            
            <!-- Formulario de login -->
            <form id="loginForm" method="POST" action="">
                <div class="campo">
                    <label for="usuario">Usuario:</label>
                    <input type="text" id="usuario" name="usuario" required>
                </div>
                
                <div class="campo">
                    <label for="password">Contraseña:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn-login" id="btnLogin">
                    Iniciar Sesión
                </button>
                
                <div class="loading" id="loading" style="display: none;">
                    Verificando credenciales...
                </div>
            </form>
            
            <!-- Información de sesión activa (oculta inicialmente) -->
            <div id="sessionInfo" style="display: none;">
                <h2>Sesión Activa</h2>
                <div id="sessionDetails"></div>
                <button onclick="logout()" class="btn-logout">Cerrar Sesión</button>
            </div>
        </div>
        
        <!-- Enlaces de navegación (ocultos inicialmente) -->
        <div id="navLinks" style="display: none;">
            <a href="index.php" class="nav-link">Ir al Sistema</a>
        </div>
    </main>
    
    <script>
        // JavaScript mejorado para el login
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const usuario = document.getElementById('usuario').value;
            const password = document.getElementById('password').value;
            const loading = document.getElementById('loading');
            const alertContainer = document.getElementById('alertContainer');
            const btnLogin = document.getElementById('btnLogin');
            
            // Mostrar loading
            loading.style.display = 'block';
            btnLogin.disabled = true;
            alertContainer.innerHTML = '';
            
            // Crear FormData
            const formData = new FormData();
            formData.append('api', 'login');
            formData.append('usuario', usuario);
            formData.append('password', password);
            
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                btnLogin.disabled = false;
                
                if (data.success) {
                    alertContainer.innerHTML = `
                        <div style="color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;">
                            ¡Login exitoso! Redirigiendo...
                        </div>
                    `;
                    
                    // Redirigir después de 1 segundo
                    setTimeout(() => {
                        window.location.href = data.redirect || 'index.php';
                    }, 1000);
                } else {
                    alertContainer.innerHTML = `
                        <div style="color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;">
                            Error: ${data.error}
                        </div>
                    `;
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                btnLogin.disabled = false;
                alertContainer.innerHTML = `
                    <div style="color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;">
                        Error de conexión: ${error.message}
                    </div>
                `;
            });
        });
        
        // Función para logout
        function logout() {
            const formData = new FormData();
            formData.append('api', 'logout');
            
            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                }
            });
        }
    </script>
</body>
</html>