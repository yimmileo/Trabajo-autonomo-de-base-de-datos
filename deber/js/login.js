document.addEventListener('DOMContentLoaded', () => {
    const usuario = document.getElementById('usuario');
    const contraseña = document.getElementById('contraseña');
    const btnlogin = document.getElementById('btnlogin');

    // Credenciales válidas
    const USUARIO_VALIDO = 'yimjosmar';
    const CONTRASEÑA_VALIDA = '12122204';

    usuario.addEventListener('input', validarUsuario);
    contraseña.addEventListener('input', validarContraseña);

    btnlogin.addEventListener('click', (e) => {
        e.preventDefault();
        if (validarFormulario()) {
            // Validar credenciales específicas
            if (usuario.value === USUARIO_VALIDO && contraseña.value === CONTRASEÑA_VALIDA) {
                alert('¡Inicio de sesión exitoso! Bienvenido ' + USUARIO_VALIDO);
                localStorage.setItem('usuarioLogeado', 'true'); //guardar estado de sesión
                window.location.href = 'index.php';
            } else {
                alert('Usuario o contraseña incorrectos. Inténtalo de nuevo.');
                // Marcar campos como inválidos
                usuario.classList.add('invalido');
                contraseña.classList.add('invalido');
            }
        }
    });

    function validarUsuario() {
        const error = document.getElementById('errorusuario');
        if (usuario.value.trim() === '') {
            error.textContent = 'El usuario es obligatorio.';
            usuario.classList.add('invalido');
            usuario.classList.remove('valido');
            return false;
        } else {
            error.textContent = '';
            usuario.classList.remove('invalido');
            usuario.classList.add('valido');
            return true;
        }
    }

    function validarContraseña() {
        const error = document.getElementById('errorContraseña');
        if (contraseña.value.trim() === '') {
            error.textContent = 'La contraseña es obligatoria.';
            contraseña.classList.add('invalido');
            contraseña.classList.remove('valido');
            return false;
        } else if (contraseña.value.length < 3) {
            error.textContent = 'La contraseña debe tener al menos 8 caracteres.';
            contraseña.classList.add('invalido');
            contraseña.classList.remove('valido');
            return false;
        } else {
            error.textContent = '';
            contraseña.classList.remove('invalido');
            contraseña.classList.add('valido');
            return true;
        }
    }

    function validarFormulario() {
        const usuarioValido = validarUsuario();
        const contraseñaValida = validarContraseña();
        
        return usuarioValido && contraseñaValida;
    }
});