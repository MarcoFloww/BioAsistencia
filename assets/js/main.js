const BIOASISTENCIA_BASE_URL = (() => {
    const scriptActual = document.currentScript;

    if (!scriptActual || !scriptActual.src) {
        return '';
    }

    return new URL('../../', scriptActual.src).pathname.replace(/\/$/, '');
})();

'use strict';

(function () {
    const INTERVALO_ESTADO_MS = 5000;
    const TIEMPO_LIMITE_PETICION_MS = 8000;
    const DURACION_MENSAJE_MS = 5200;
    const DURACION_TRANSICION_MS = 260;
    const RETRASO_BUSQUEDA_MS = 180;

    const ESTADOS_DISPOSITIVO = {
        'Sistema Activo': 'sistema-activo',
        'Estado Apagado': 'estado-apagado'
    };

    const ICONOS_MENSAJE = {
        exito: `
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20 6 9 17l-5-5"></path>
            </svg>
        `,
        error: `
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <line x1="12" y1="8" x2="12" y2="13"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        `,
        info: `
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle>
                <line x1="12" y1="11" x2="12" y2="17"></line>
                <line x1="12" y1="7" x2="12.01" y2="7"></line>
            </svg>
        `
    };

    let peticionEstadoEnCurso = false;
    const temporizadoresBusqueda = new WeakMap();

    function normalizarTexto(texto) {
        return String(texto || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function debounce(funcion, espera) {
        let temporizador = null;

        return function ejecutarConRetraso(...argumentos) {
            clearTimeout(temporizador);

            temporizador = setTimeout(() => {
                funcion.apply(this, argumentos);
            }, espera);
        };
    }

    function obtenerNombrePagina() {
        const pagina = window.location.pathname.split('/').pop();

        return pagina ? pagina.toLowerCase() : 'dashboard.php';
    }

    function fetchConTiempoLimite(url, limiteMs) {
        const controlador = new AbortController();
        const temporizador = setTimeout(() => controlador.abort(), limiteMs);

        return fetch(url, {
            signal: controlador.signal,
            cache: 'no-store',
            headers: {
                Accept: 'application/json'
            }
        }).finally(() => {
            clearTimeout(temporizador);
        });
    }

    function cerrarMensaje(elemento) {
        if (!elemento || elemento.dataset.cerrando === 'true') {
            return;
        }

        elemento.dataset.cerrando = 'true';
        elemento.style.transition = `opacity ${DURACION_TRANSICION_MS}ms ease, transform ${DURACION_TRANSICION_MS}ms ease`;
        elemento.style.opacity = '0';
        elemento.style.transform = 'translateY(-8px)';

        const finalizar = () => {
            if (elemento && elemento.parentNode) {
                elemento.remove();
            }
        };

        elemento.addEventListener('transitionend', finalizar, { once: true });
        setTimeout(finalizar, DURACION_TRANSICION_MS + 120);
    }

    function programarCierreAutomatico(elemento) {
        if (!elemento || elemento.dataset.autoCierre === 'false') {
            return;
        }

        setTimeout(() => {
            cerrarMensaje(elemento);
        }, DURACION_MENSAJE_MS);
    }

    function mostrarMensaje(tipo, texto) {
        const tipoNormalizado = ['exito', 'error', 'info'].includes(tipo) ? tipo : 'info';

        const contenedor = document.createElement('div');
        contenedor.className = `mensaje-alerta mensaje-${tipoNormalizado} mensaje-flotante`;
        contenedor.setAttribute('role', tipoNormalizado === 'error' ? 'alert' : 'status');

        const icono = document.createElement('span');
        icono.className = 'icono-mensaje';
        icono.setAttribute('aria-hidden', 'true');
        icono.innerHTML = ICONOS_MENSAJE[tipoNormalizado];

        const textoSpan = document.createElement('span');
        textoSpan.textContent = texto;

        const botonCerrar = document.createElement('button');
        botonCerrar.type = 'button';
        botonCerrar.className = 'cerrar-mensaje';
        botonCerrar.setAttribute('aria-label', 'Cerrar mensaje');
        botonCerrar.textContent = '×';

        contenedor.appendChild(icono);
        contenedor.appendChild(textoSpan);
        contenedor.appendChild(botonCerrar);

        const areaContenido = document.querySelector('.area-contenido') || document.querySelector('.login-layout') || document.body;
        areaContenido.insertBefore(contenedor, areaContenido.firstChild);

        requestAnimationFrame(() => {
            contenedor.classList.add('mensaje-visible');
        });

        programarCierreAutomatico(contenedor);

        return contenedor;
    }

    function aplicarEstadoDispositivo(indicador, estado) {
        if (!indicador) {
            return;
        }

        const estadoNormalizado = estado === 'Sistema Activo' ? 'Sistema Activo' : 'Estado Apagado';
        const claseEstado = ESTADOS_DISPOSITIVO[estadoNormalizado];

        indicador.className = `indicador-dispositivo indicador-${claseEstado}`;
        indicador.textContent = '';

        const punto = document.createElement('span');
        punto.className = 'punto-indicador';

        indicador.appendChild(punto);
        indicador.appendChild(document.createTextNode(estadoNormalizado));
        indicador.title = `Actualizado ${new Date().toLocaleTimeString('es-PE')}`;
    }

    function actualizarEstadoDispositivo() {
        const indicador = document.querySelector('.indicador-dispositivo');

        if (!indicador || peticionEstadoEnCurso) {
            return;
        }

        peticionEstadoEnCurso = true;

        fetchConTiempoLimite(`${BIOASISTENCIA_BASE_URL}/api/consultar_estado.php`, TIEMPO_LIMITE_PETICION_MS)
            .then((respuesta) => {
                if (!respuesta.ok) {
                    throw new Error('Respuesta no válida');
                }

                return respuesta.json();
            })
            .then((datos) => {
                aplicarEstadoDispositivo(indicador, datos.estado || datos.estado_biometrico || 'Estado Apagado');
            })
            .catch(() => {
                if (indicador.textContent.trim() === '') {
                    aplicarEstadoDispositivo(indicador, 'Estado Apagado');
                }
            })
            .finally(() => {
                peticionEstadoEnCurso = false;
            });
    }

    function inicializarIndicadorDispositivo() {
        const indicador = document.querySelector('.indicador-dispositivo');

        if (!indicador) {
            return;
        }

        actualizarEstadoDispositivo();
        setInterval(actualizarEstadoDispositivo, INTERVALO_ESTADO_MS);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                actualizarEstadoDispositivo();
            }
        });
    }

    function inicializarMensajesExistentes() {
        document.querySelectorAll('.mensaje-alerta').forEach((mensaje) => {
            if (!mensaje.querySelector('.cerrar-mensaje')) {
                const botonCerrar = document.createElement('button');
                botonCerrar.type = 'button';
                botonCerrar.className = 'cerrar-mensaje';
                botonCerrar.setAttribute('aria-label', 'Cerrar mensaje');
                botonCerrar.textContent = '×';

                mensaje.appendChild(botonCerrar);
            }

            programarCierreAutomatico(mensaje);
        });
    }

    function alternarVisibilidadContrasena(boton) {
        const idCampo = boton.getAttribute('data-toggle-password');
        const campoPorId = idCampo ? document.getElementById(idCampo) : null;
        const contenedor = boton.closest('.campo-contrasena, .login-control, .login-control-password, .campo-login-control');
        const campo = campoPorId || (contenedor ? contenedor.querySelector('input[type="password"], input[type="text"]') : null);

        if (!campo) {
            return;
        }

        const estabaOculto = campo.type === 'password';

        campo.type = estabaOculto ? 'text' : 'password';
        boton.textContent = estabaOculto ? 'Ocultar' : 'Ver';
        boton.setAttribute('aria-pressed', String(estabaOculto));
    }

    function filtrarTabla(campo) {
        const tablaId = campo.getAttribute('data-tabla');
        const tabla = tablaId ? document.getElementById(tablaId) : campo.closest('.tarjeta-panel, .tabla-contenedor, .seccion-tabla')?.querySelector('table');

        if (!tabla) {
            return;
        }

        const cuerpo = tabla.querySelector('tbody');

        if (!cuerpo) {
            return;
        }

        const consultaOriginal = campo.value.trim();
        const consulta = normalizarTexto(consultaOriginal);
        const filas = Array.from(cuerpo.querySelectorAll('tr:not(.fila-sin-resultados)'));
        let visibles = 0;

        filas.forEach((fila) => {
            const coincide = normalizarTexto(fila.textContent).includes(consulta);
            fila.style.display = coincide ? '' : 'none';

            if (coincide) {
                visibles += 1;
            }
        });

        let filaVacia = cuerpo.querySelector('.fila-sin-resultados');

        if (visibles === 0 && consulta !== '') {
            if (!filaVacia) {
                const columnas = tabla.querySelectorAll('thead th').length || 1;

                filaVacia = document.createElement('tr');
                filaVacia.className = 'fila-sin-resultados';

                const celda = document.createElement('td');
                celda.colSpan = columnas;
                celda.className = 'texto-sin-datos';
                celda.textContent = `Sin resultados para "${consultaOriginal}"`;

                filaVacia.appendChild(celda);
                cuerpo.appendChild(filaVacia);
            }
        } else if (filaVacia) {
            filaVacia.remove();
        }
    }

    function manejarBusqueda(campo) {
        if (!temporizadoresBusqueda.has(campo)) {
            temporizadoresBusqueda.set(campo, debounce(() => {
                filtrarTabla(campo);
            }, RETRASO_BUSQUEDA_MS));
        }

        temporizadoresBusqueda.get(campo)();
    }

    function crearBotonMenuMovil() {
        if (!document.body.classList.contains('pagina-interna')) {
            return;
        }

        if (document.querySelector('.boton-menu-movil')) {
            return;
        }

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'boton-menu-movil';
        boton.setAttribute('aria-label', 'Abrir menú');
        boton.innerHTML = '<span></span><span></span><span></span>';

        document.body.appendChild(boton);
    }

    function alternarMenuMovil() {
        document.body.classList.toggle('menu-movil-abierto');
    }

    function cerrarMenuMovil() {
        document.body.classList.remove('menu-movil-abierto');
    }

    function marcarMenuActivo() {
        const paginaActual = obtenerNombrePagina();

        document.querySelectorAll('.enlace-menu').forEach((enlace) => {
            const href = (enlace.getAttribute('href') || '').split('/').pop().toLowerCase();

            if (href === paginaActual) {
                enlace.classList.add('activo');
            } else {
                enlace.classList.remove('activo');
            }
        });
    }

    function inicializarModales() {
        document.querySelectorAll('.fondo-modal').forEach((modal) => {
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
        });
    }

    function cerrarModalAbierto() {
        const modales = Array.from(document.querySelectorAll('.fondo-modal'));

        const modalVisible = modales.find((modal) => {
            const estilo = window.getComputedStyle(modal);

            return estilo.display !== 'none' && estilo.visibility !== 'hidden' && estilo.opacity !== '0';
        });

        if (modalVisible) {
            modalVisible.style.display = 'none';
        }
    }

    function inicializarAnimaciones() {
        const elementos = document.querySelectorAll(
            '.tarjeta-panel, .tarjeta-estadistica, .stat-card, .modulo-hero, .login-tarjeta-premium, .login-hero-contenido'
        );

        if (!elementos.length) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            elementos.forEach((elemento) => elemento.classList.add('animacion-visible'));
            return;
        }

        const observador = new IntersectionObserver((entradas) => {
            entradas.forEach((entrada) => {
                if (entrada.isIntersecting) {
                    entrada.target.classList.add('animacion-visible');
                    observador.unobserve(entrada.target);
                }
            });
        }, {
            threshold: 0.12
        });

        elementos.forEach((elemento) => {
            elemento.classList.add('animacion-entrada');
            observador.observe(elemento);
        });
    }

    function actualizarRelojes() {
        const ahora = new Date();

        const hora = ahora.toLocaleTimeString('es-PE', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });

        const fecha = ahora.toLocaleDateString('es-PE', {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        });

        document.querySelectorAll('[data-reloj], #relojSistema, #loginHora').forEach((elemento) => {
            elemento.textContent = hora;
        });

        document.querySelectorAll('[data-fecha], #fechaSistema, #loginFecha').forEach((elemento) => {
            elemento.textContent = fecha;
        });
    }

    function inicializarRelojes() {
        const tieneReloj = document.querySelector('[data-reloj], #relojSistema, #loginHora');
        const tieneFecha = document.querySelector('[data-fecha], #fechaSistema, #loginFecha');

        if (!tieneReloj && !tieneFecha) {
            return;
        }

        actualizarRelojes();
        setInterval(actualizarRelojes, 1000);
    }

    function inicializarCargaBotones() {
        document.querySelectorAll('form').forEach((formulario) => {
            formulario.addEventListener('submit', () => {
                const boton = formulario.querySelector('button[type="submit"], button:not([type]), .boton-login-principal, .login-boton-principal');

                if (!boton || boton.dataset.confirmar) {
                    return;
                }

                boton.classList.add('boton-cargando');

                if (!boton.dataset.textoOriginal) {
                    boton.dataset.textoOriginal = boton.textContent.trim();
                }

                setTimeout(() => {
                    boton.classList.remove('boton-cargando');
                }, 2500);
            });
        });
    }

    function manejarClicGlobal(evento) {
        const botonPassword = evento.target.closest(
            '[data-toggle-password], .boton-ver-contrasena, .boton-ver-password, .login-boton-ver, #botonVerContrasena'
        );

        if (botonPassword) {
            alternarVisibilidadContrasena(botonPassword);
            return;
        }

        const botonCerrarMensaje = evento.target.closest('.cerrar-mensaje');

        if (botonCerrarMensaje) {
            cerrarMensaje(botonCerrarMensaje.closest('.mensaje-alerta'));
            return;
        }

        const botonMenuMovil = evento.target.closest('.boton-menu-movil');

        if (botonMenuMovil) {
            alternarMenuMovil();
            return;
        }

        const enlaceMenu = evento.target.closest('.enlace-menu');

        if (enlaceMenu) {
            cerrarMenuMovil();
            return;
        }

        const modal = evento.target.classList.contains('fondo-modal') ? evento.target : null;

        if (modal) {
            modal.style.display = 'none';
            return;
        }

        const elementoConfirmar = evento.target.closest('[data-confirmar]');

        if (elementoConfirmar) {
            const mensaje = elementoConfirmar.getAttribute('data-confirmar') || '¿Deseas continuar?';

            if (!window.confirm(mensaje)) {
                evento.preventDefault();
                evento.stopPropagation();
            }
        }
    }

    function manejarEntradaGlobal(evento) {
        if (evento.target.matches('.campo-busqueda, [data-tabla]')) {
            manejarBusqueda(evento.target);
        }
    }

    function manejarTecladoGlobal(evento) {
        if (evento.key === 'Escape') {
            cerrarModalAbierto();
            cerrarMenuMovil();
        }

        if ((evento.ctrlKey || evento.metaKey) && evento.key.toLowerCase() === 'k') {
            const buscador = document.querySelector('input[name="buscar"], .campo-busqueda, [data-tabla]');

            if (buscador) {
                evento.preventDefault();
                buscador.focus();
                buscador.select();
            }
        }
    }

    function inicializarAplicacion() {
        crearBotonMenuMovil();
        marcarMenuActivo();
        inicializarIndicadorDispositivo();
        inicializarMensajesExistentes();
        inicializarModales();
        inicializarAnimaciones();
        inicializarRelojes();
        inicializarCargaBotones();

        document.addEventListener('click', manejarClicGlobal);
        document.addEventListener('input', manejarEntradaGlobal);
        document.addEventListener('keydown', manejarTecladoGlobal);
    }

    document.addEventListener('DOMContentLoaded', inicializarAplicacion);

    window.mostrarMensaje = mostrarMensaje;
    window.cerrarModalAbierto = cerrarModalAbierto;
})();