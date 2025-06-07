<?php
$servidor = "127.0.0.1";
$usuario = "usuarioweb";
$contrasena = "web123";
$basedatos = "deber";

$conn = new mysqli($servidor, $usuario, $contrasena, $basedatos);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
} 
?>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>🍳 El hueco, recetario</title>
        <link rel="stylesheet" href="css/pagina_inicio.css">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <header>
            <h1>🍳 El hueco, recetario</h1>
            <nav>
                <ul>
                    <li id="navLogin"><a href="login.php">Iniciar Sesión</a></li>
                    
                </ul>
            </nav>
        </header>

        <main>
        </main>

        <script src="js/script.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>

