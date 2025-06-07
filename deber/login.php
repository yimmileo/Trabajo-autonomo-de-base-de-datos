<?php
try {
    $conn = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=deber", "usuarioweb", "web123");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

?>

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
            <form id="formlogin">
                <div class="campo">
                    <label for="usuario">Usuario:</label>
                    <input type="text" id="usuario" required>
                    <span class="mensaje-error" id="errorusuario"></span>
                </div>
                <div class="campo">
                    <label for="contraseña">Contraseña:</label>
                    <input type="password" id="contraseña" required>
                    <span class="mensaje-error" id="errorContraseña"></span>
                </div>
                <button type="button" id="btnlogin">Iniciar Sesión</button>
            </form>
            
        </div>
    </main>

    
    <script src="js/login.js"></script>
</body>
</html>