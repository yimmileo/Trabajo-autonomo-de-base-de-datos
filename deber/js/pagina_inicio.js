document.addEventListener('DOMContentLoaded', () => {
    const usuarioLogeado = localStorage.getItem('usuarioLogeado');

    const tarjetas = document.getElementById('seccionTarjetas');
    const cerrarSesionLi = document.getElementById('cerrarSesionLi');
    const navLogin = document.getElementById('navLogin');
    const navSignup = document.getElementById('navSignup');

    if (usuarioLogeado === 'true') {
        if (tarjetas) tarjetas.style.display = 'block';
        if (cerrarSesionLi) cerrarSesionLi.style.display = 'inline-block';
        if (navLogin) navLogin.style.display = 'none';
        if (navSignup) navSignup.style.display = 'none';
    } else {
        if (tarjetas) tarjetas.style.display = 'none';
        if (cerrarSesionLi) cerrarSesionLi.style.display = 'none';
        if (navLogin) navLogin.style.display = 'inline-block';
        if (navSignup) navSignup.style.display = 'inline-block';
    }
});


function cerrarSesion() {
    if (confirm("¿Estás seguro de que deseas cerrar sesión?")) {
        localStorage.removeItem('usuario');
        document.getElementById('cerrarSesionLi').style.display = 'none';
        document.getElementById('seccionTarjetas').style.display = 'none';
        document.getElementById('navLogin').style.display = 'block';
        document.getElementById('navSignup').style.display = 'block';
        alert("¡Sesión cerrada exitosamente!");
    }
}

function abrirModal() {
    document.getElementById('modalReceta').style.display = 'block';
}

function cerrarModal() {
    document.getElementById('modalReceta').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('modalReceta');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('formularioReceta').addEventListener('submit', function(e) {
    e.preventDefault();
    var nombre = document.getElementById('nombreReceta').value;
    var foto = document.getElementById('fotoReceta').files[0];
    var descripcion = document.getElementById('descripcionReceta').value;
    agregarNuevaReceta(nombre, foto, descripcion);
    alert('Receta "' + nombre + '" subida exitosamente!');
    this.reset();
    cerrarModal();
});

function agregarNuevaReceta(nombre, archivo, descripcion) {
    var recetasGrid = document.querySelector('.recetas-grid');
    var nuevaReceta = document.createElement('article');
    nuevaReceta.className = 'receta';
    var imagenUrl = '';
    if (archivo) {
        var reader = new FileReader();
        reader.onload = function(e) {
            imagenUrl = e.target.result;
            var receta = {
                titulo: nombre,
                ingredientes: "Ingredientes personalizados",
                imagen: imagenUrl,
                descripcion: descripcion
            };
            guardarRecetaEnStorage(receta);
            mostrarRecetaEnPagina(nombre, imagenUrl, descripcion);
        };
        reader.readAsDataURL(archivo);
    } else {
        imagenUrl = 'https://via.placeholder.com/300x180/b08968/ffffff?text=Sin+Imagen';
        var receta = {
            titulo: nombre,
            ingredientes: "Ingredientes personalizados",
            imagen: imagenUrl,
            descripcion: descripcion
        };
        guardarRecetaEnStorage(receta);
        mostrarRecetaEnPagina(nombre, imagenUrl, descripcion);
    }
}

function mostrarRecetaEnPagina(nombre, imagenUrl, descripcion) {
    var recetasGrid = document.querySelector('.recetas-grid');
    var nuevaReceta = document.createElement('article');
    nuevaReceta.className = 'receta';
    
    nuevaReceta.innerHTML = `
        <h3>${nombre}</h3>
        <img src="${imagenUrl}" alt="${nombre}">
        <p>${descripcion}</p>
    `;
    recetasGrid.appendChild(nuevaReceta);
}

function guardarRecetaEnStorage(receta) {
    var recetasGuardadas = JSON.parse(localStorage.getItem('recetasPersonalizadas')) || [];
    recetasGuardadas.push(receta);
    localStorage.setItem('recetasPersonalizadas', JSON.stringify(recetasGuardadas));
}

window.addEventListener('load', function() {
    var recetasGuardadas = JSON.parse(localStorage.getItem('recetasPersonalizadas')) || [];
    recetasGuardadas.forEach(function(receta) {
        mostrarRecetaEnPagina(receta.titulo, receta.imagen, receta.descripcion);
    });
});
