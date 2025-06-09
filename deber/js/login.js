// login.js
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM cargado, iniciando script de login');
    
    const loginForm = document.getElementById('loginForm');
    const loading = document.getElementById('loading');
    const btnLogin = document.getElementById('btnLogin');
    const alertContainer = document.getElementById('alertContainer');
    const sessionInfo = document.getElementById('sessionInfo');
    const navLinks = document.getElementById('navLinks');

    // Verificar que los elementos existen
    if (!loginForm) {
        console.error('No se encontró el formulario de login');
        return;
    }

    console.log('Elementos encontrados, configurando eventos');

    // Verificar si ya hay sesión activa al cargar la página
    checkSession();

    // Manejar el envío del formulario
    loginForm.addEventListener('submit', function(e) {
        console.log('Formulario enviado');
        e.preventDefault();
        e.stopPropagation();
        
        const usuario = document.getElementById('usuario').value.trim();
        const password = document.getElementById('password').value.trim();
        
        console.log('Datos del formulario:', { usuario: usuario, password: '***' });
        
        if (!usuario || !password) {
            showAlert('Por favor, complete todos los campos', 'error');
            return false;
        }
        
        // Mostrar indicador de carga
        if (loading) loading.style.display = 'block';
        if (btnLogin) {
            btnLogin.disabled = true;
            btnLogin.textContent = 'Verificando...';
        }
        
        // Crear FormData para enviar los datos
        const formData = new FormData();
        formData.append('api', 'login');
        formData.append('usuario', usuario);
        formData.append('password', password);
        
        console.log('Enviando petición de login...');
        
        // Realizar petición de login
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Respuesta recibida:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Texto de respuesta:', text);
            try {
                const data = JSON.parse(text);
                console.log('Datos parseados:', data);
                handleLoginResponse(data);
            } catch (e) {
                console.error('Error al parsear JSON:', e);
                console.log('Respuesta recibida:', text.substring(0, 500));
                showAlert('Error de formato en la respuesta del servidor', 'error');
                resetLoginButton();
            }
        })
        .catch(error => {
            console.error('Error en la petición:', error);
            showAlert('Error de conexión. Intente nuevamente.', 'error');
            resetLoginButton();
        });
        
        return false; // Prevenir envío normal del formulario
    });
    
    // Función para manejar la respuesta del login
    function handleLoginResponse(data) {
        if (data.success) {
            showAlert(`¡Bienvenido ${data.usuario}!`, 'success');
            
            // Mostrar información de sesión
            showSessionInfo(data);
            
            // Redirigir al sistema principal después de 2 segundos
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 2000);
            
        } else {
            showAlert(data.error || 'Error al iniciar sesión', 'error');
            resetLoginButton();
        }
    }
    
    // Función para resetear el botón de login
    function resetLoginButton() {
        if (loading) loading.style.display = 'none';
        if (btnLogin) {
            btnLogin.disabled = false;
            btnLogin.textContent = 'Iniciar Sesión';
        }
    }

    // Función para verificar sesión actual
    function checkSession() {
        fetch(window.location.href + '?api=session')
        .then(response => response.json())
        .then(data => {
            if (data.authenticated) {
                showSessionInfo(data);
                loginForm.style.display = 'none';
            }
        })
        .catch(error => {
            console.log('No hay sesión activa');
        });
    }

    // Función para mostrar información de sesión
    function showSessionInfo(data) {
        const sessionDetails = document.getElementById('sessionDetails');
        sessionDetails.innerHTML = `
            <p><strong>Usuario:</strong> ${data.usuario}</p>
            <p><strong>Rol:</strong> ${data.rol}</p>
            <p><strong>Inicio de sesión:</strong> ${data.login_time}</p>
        `;
        
        sessionInfo.style.display = 'block';
        navLinks.style.display = 'block';
        loginForm.style.display = 'none';
    }

    // Función para mostrar alertas
    function showAlert(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `
            <span>${message}</span>
            <button type="button" class="close-alert" onclick="this.parentElement.remove()">×</button>
        `;
        
        alertContainer.innerHTML = '';
        alertContainer.appendChild(alertDiv);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (alertDiv.parentElement) {
                alertDiv.remove();
            }
        }, 5000);
    }
});

// Función global para logout
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
            alert('Sesión cerrada correctamente');
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error al cerrar sesión:', error);
        alert('Error al cerrar sesión');
    });
}