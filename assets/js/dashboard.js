(function () {
    'use strict';

    const DATOS_DEFECTO = {
        semana: [],
        general: {
            puntuales: 0,
            tardanzas: 0,
            faltas: 0,
            total: 0
        }
    };

    const CONFIG = {
        relojId: 'dashboardClock',
        fechaId: 'dashboardDate',
        graficoSemanaId: 'weeklyAttendanceChart',
        graficoGeneralId: 'generalAttendanceChart',
        retrasoRedimension: 180,
        duracionAnimacion: 850
    };

    let modoGraficoSemana = 'total';
    let temporizadorRedimension = null;

    const datos = normalizarDatos(window.bioDashboardData || DATOS_DEFECTO);

    function cssVar(nombre, respaldo) {
        const valor = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();

        return valor || respaldo;
    }

    function color(nombre, respaldo) {
        return cssVar(nombre, respaldo);
    }

    function numero(valor, respaldo = 0) {
        const convertido = Number(valor);

        return Number.isFinite(convertido) ? convertido : respaldo;
    }

    function limitar(valor, minimo, maximo) {
        return Math.min(Math.max(valor, minimo), maximo);
    }

    function normalizarDatos(origen) {
        const semanaBase = Array.isArray(origen.semana) ? origen.semana : [];
        const semana = semanaBase.map((item) => {
            const puntuales = numero(item.puntuales);
            const tardanzas = numero(item.tardanzas);
            const faltas = numero(item.faltas);
            const totalCalculado = puntuales + tardanzas + faltas;
            const total = numero(item.total, totalCalculado);

            return {
                dia: item.dia || item.nombre || 'Día',
                total: total > 0 ? total : totalCalculado,
                puntuales,
                tardanzas,
                faltas
            };
        });

        const general = origen.general || {};
        const puntuales = numero(general.puntuales);
        const tardanzas = numero(general.tardanzas);
        const faltas = numero(general.faltas);
        const total = numero(general.total, puntuales + tardanzas + faltas);

        return {
            semana,
            general: {
                puntuales,
                tardanzas,
                faltas,
                total: total > 0 ? total : puntuales + tardanzas + faltas
            }
        };
    }

    function obtenerDiasSemana() {
        if (datos.semana.length > 0) {
            return datos.semana;
        }

        return [
            { dia: 'Lun', total: 0, puntuales: 0, tardanzas: 0, faltas: 0 },
            { dia: 'Mar', total: 0, puntuales: 0, tardanzas: 0, faltas: 0 },
            { dia: 'Mié', total: 0, puntuales: 0, tardanzas: 0, faltas: 0 },
            { dia: 'Jue', total: 0, puntuales: 0, tardanzas: 0, faltas: 0 },
            { dia: 'Vie', total: 0, puntuales: 0, tardanzas: 0, faltas: 0 }
        ];
    }

    function prepararCanvas(canvas) {
        if (!canvas || !canvas.getContext) {
            return null;
        }

        const rect = canvas.getBoundingClientRect();
        const anchoCss = Math.max(280, rect.width || Number(canvas.getAttribute('width')) || 420);
        const altoCss = Math.max(220, rect.height || Number(canvas.getAttribute('height')) || 260);
        const dpr = window.devicePixelRatio || 1;

        canvas.width = Math.floor(anchoCss * dpr);
        canvas.height = Math.floor(altoCss * dpr);

        const contexto = canvas.getContext('2d');
        contexto.setTransform(dpr, 0, 0, dpr, 0, 0);

        return {
            contexto,
            ancho: anchoCss,
            alto: altoCss
        };
    }

    function dibujarBarraRedondeada(contexto, x, y, ancho, alto, radio) {
        const r = Math.min(radio, ancho / 2, alto / 2);

        contexto.beginPath();
        contexto.moveTo(x + r, y);
        contexto.arcTo(x + ancho, y, x + ancho, y + alto, r);
        contexto.arcTo(x + ancho, y + alto, x, y + alto, r);
        contexto.arcTo(x, y + alto, x, y, r);
        contexto.arcTo(x, y, x + ancho, y, r);
        contexto.closePath();
        contexto.fill();
    }

    function limpiarCanvas(contexto, ancho, alto) {
        contexto.clearRect(0, 0, ancho, alto);
    }

    function dibujarTextoVacio(contexto, ancho, alto, texto) {
        contexto.textAlign = 'center';
        contexto.textBaseline = 'middle';
        contexto.fillStyle = color('--texto-secundario', 'rgba(148, 163, 184, 0.82)');
        contexto.font = '700 13px Inter, system-ui, sans-serif';
        contexto.fillText(texto, ancho / 2, alto / 2);
    }

    function obtenerValorDia(dia) {
        if (modoGraficoSemana === 'puntuales') {
            return numero(dia.puntuales);
        }

        if (modoGraficoSemana === 'tardanzas') {
            return numero(dia.tardanzas);
        }

        if (modoGraficoSemana === 'faltas') {
            return numero(dia.faltas);
        }

        return numero(dia.total);
    }

    function obtenerColorModo() {
        if (modoGraficoSemana === 'puntuales') {
            return color('--verde', '#22c55e');
        }

        if (modoGraficoSemana === 'tardanzas') {
            return color('--naranja', '#f59e0b');
        }

        if (modoGraficoSemana === 'faltas') {
            return color('--rojo', '#fb7185');
        }

        return color('--celeste-neon', '#38bdf8');
    }

    function dibujarGraficoSemanal() {
        const canvas = document.getElementById(CONFIG.graficoSemanaId);
        const preparado = prepararCanvas(canvas);

        if (!preparado) {
            return;
        }

        const ctx = preparado.contexto;
        const ancho = preparado.ancho;
        const alto = preparado.alto;
        const dias = obtenerDiasSemana();
        const valores = dias.map(obtenerValorDia);
        const maximo = Math.max(1, ...valores);
        const padding = {
            top: 30,
            right: 22,
            bottom: 42,
            left: 34
        };
        const anchoGrafico = ancho - padding.left - padding.right;
        const altoGrafico = alto - padding.top - padding.bottom;
        const separacion = limitar(anchoGrafico * 0.035, 9, 16);
        const anchoBarra = Math.max(28, (anchoGrafico - separacion * (dias.length - 1)) / dias.length);
        const colorPrincipal = obtenerColorModo();

        limpiarCanvas(ctx, ancho, alto);

        ctx.lineWidth = 1;
        ctx.strokeStyle = color('--linea-suave', 'rgba(148, 163, 184, 0.12)');

        for (let i = 0; i <= 4; i++) {
            const y = padding.top + altoGrafico - (altoGrafico / 4) * i;

            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(ancho - padding.right, y);
            ctx.stroke();
        }

        const totalSemana = valores.reduce((suma, valor) => suma + valor, 0);

        if (totalSemana <= 0) {
            dibujarTextoVacio(ctx, ancho, alto, 'Sin datos de asistencia para mostrar');
        }

        dias.forEach((dia, index) => {
            const valor = valores[index];
            const alturaBarra = valor > 0 ? Math.max(8, (valor / maximo) * altoGrafico) : 6;
            const x = padding.left + index * (anchoBarra + separacion);
            const y = padding.top + altoGrafico - alturaBarra;

            const gradiente = ctx.createLinearGradient(0, y, 0, padding.top + altoGrafico);
            gradiente.addColorStop(0, colorPrincipal);
            gradiente.addColorStop(0.55, color('--azul-electrico', '#2563eb'));
            gradiente.addColorStop(1, 'rgba(37, 99, 235, 0.25)');

            ctx.fillStyle = valor > 0 ? gradiente : 'rgba(148, 163, 184, 0.15)';
            dibujarBarraRedondeada(ctx, x, y, anchoBarra, alturaBarra, 11);

            ctx.textAlign = 'center';
            ctx.textBaseline = 'alphabetic';

            ctx.fillStyle = color('--texto-principal', '#f8fafc');
            ctx.font = '900 12px Inter, system-ui, sans-serif';
            ctx.fillText(String(valor), x + anchoBarra / 2, Math.max(16, y - 8));

            ctx.fillStyle = color('--texto-secundario', 'rgba(148, 163, 184, 0.82)');
            ctx.font = '800 11px Inter, system-ui, sans-serif';
            ctx.fillText(String(dia.dia), x + anchoBarra / 2, alto - 14);
        });
    }

    function dibujarGraficoGeneral() {
        const canvas = document.getElementById(CONFIG.graficoGeneralId);
        const preparado = prepararCanvas(canvas);

        if (!preparado) {
            return;
        }

        const ctx = preparado.contexto;
        const ancho = preparado.ancho;
        const alto = preparado.alto;
        const medida = Math.min(ancho, alto);
        const centroX = ancho / 2;
        const centroY = alto / 2;
        const radio = Math.max(62, medida * 0.31);
        const grosor = Math.max(17, medida * 0.085);
        const valores = [
            datos.general.puntuales,
            datos.general.tardanzas,
            datos.general.faltas
        ];
        const total = valores.reduce((suma, valor) => suma + valor, 0);
        const colores = [
            color('--verde', '#22c55e'),
            color('--naranja', '#f59e0b'),
            color('--rojo', '#fb7185')
        ];

        limpiarCanvas(ctx, ancho, alto);

        ctx.lineWidth = grosor;
        ctx.lineCap = 'round';

        ctx.beginPath();
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.15)';
        ctx.arc(centroX, centroY, radio, 0, Math.PI * 2);
        ctx.stroke();

        if (total > 0) {
            let inicio = -Math.PI / 2;

            valores.forEach((valor, index) => {
                const angulo = (valor / total) * Math.PI * 2;

                if (angulo <= 0) {
                    return;
                }

                ctx.beginPath();
                ctx.strokeStyle = colores[index];
                ctx.shadowColor = colores[index];
                ctx.shadowBlur = 14;
                ctx.arc(centroX, centroY, radio, inicio, inicio + angulo);
                ctx.stroke();
                ctx.shadowBlur = 0;

                inicio += angulo;
            });
        }

        const porcentajePuntualidad = total > 0 ? Math.round((datos.general.puntuales / total) * 100) : 0;

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        ctx.fillStyle = color('--texto-principal', '#f8fafc');
        ctx.font = '900 34px Inter, system-ui, sans-serif';
        ctx.fillText(`${porcentajePuntualidad}%`, centroX, centroY - 2);

        ctx.fillStyle = color('--texto-secundario', 'rgba(148, 163, 184, 0.82)');
        ctx.font = '800 11px Inter, system-ui, sans-serif';
        ctx.fillText('Puntualidad', centroX, centroY + 28);

        if (total <= 0) {
            ctx.font = '700 11px Inter, system-ui, sans-serif';
            ctx.fillText('Sin registros', centroX, centroY + 48);
        }
    }

    function renderizarGraficos() {
        dibujarGraficoSemanal();
        dibujarGraficoGeneral();
    }

    function configurarReloj() {
        const reloj = document.getElementById(CONFIG.relojId);
        const fecha = document.getElementById(CONFIG.fechaId);

        if (!reloj && !fecha) {
            return;
        }

        const actualizar = () => {
            const ahora = new Date();

            if (reloj) {
                reloj.textContent = ahora.toLocaleTimeString('es-PE', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }

            if (fecha) {
                fecha.textContent = ahora.toLocaleDateString('es-PE', {
                    weekday: 'long',
                    day: '2-digit',
                    month: 'long',
                    year: 'numeric'
                });
            }
        };

        actualizar();
        setInterval(actualizar, 1000);
    }

    function configurarAnimaciones() {
        const elementos = document.querySelectorAll(
            '.reveal-card, .premium-stat-card, .premium-panel, .hero-dashboard-card, .tarjeta-panel, .tarjeta-estadistica'
        );

        if (!elementos.length) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            elementos.forEach((elemento) => elemento.classList.add('visible', 'animacion-visible'));
            return;
        }

        const observador = new IntersectionObserver((entradas) => {
            entradas.forEach((entrada) => {
                if (entrada.isIntersecting) {
                    entrada.target.classList.add('visible', 'animacion-visible');
                    observador.unobserve(entrada.target);
                }
            });
        }, {
            threshold: 0.12
        });

        elementos.forEach((elemento, index) => {
            elemento.style.setProperty('--delay', `${index * 55}ms`);
            observador.observe(elemento);
        });
    }

    function configurarBrilloInteractivo() {
        const paneles = document.querySelectorAll(
            '.premium-stat-card, .premium-panel, .hero-dashboard-card, .tarjeta-panel, .tarjeta-estadistica'
        );

        paneles.forEach((panel) => {
            panel.addEventListener('mousemove', (evento) => {
                const rect = panel.getBoundingClientRect();
                const x = evento.clientX - rect.left;
                const y = evento.clientY - rect.top;

                panel.style.setProperty('--mx', `${x}px`);
                panel.style.setProperty('--my', `${y}px`);
            });

            panel.addEventListener('mouseleave', () => {
                panel.style.removeProperty('--mx');
                panel.style.removeProperty('--my');
            });
        });
    }

function configurarBotonesSegmentados() {
    document.querySelectorAll('.segmented-control button, [data-dashboard-modo]').forEach((boton) => {
        boton.addEventListener('click', () => {
            const grupo = boton.closest('.segmented-control') || boton.parentElement;

            if (grupo) {
                grupo.querySelectorAll('button').forEach((item) => {
                    item.classList.remove('active', 'activo');
                });
            }

            boton.classList.add('active', 'activo');

            const modo = boton.getAttribute('data-dashboard-modo') || boton.getAttribute('data-mode');

            if (modo) {
                modoGraficoSemana = modo;
                dibujarGraficoSemanal();
            }
        });
    });
}
function animarContadores() {
    const elementos = document.querySelectorAll('[data-contador], .contador-animado');

    elementos.forEach((elemento) => {
        const textoOriginal = elemento.getAttribute('data-contador') || elemento.textContent.trim();
        const numeroEncontrado = String(textoOriginal).match(/\d+(\.\d+)?/);

        if (!numeroEncontrado) {
            return;
        }

        const objetivo = numero(numeroEncontrado[0]);

        if (objetivo <= 0) {
            return;
        }

        const prefijo = String(textoOriginal).slice(0, numeroEncontrado.index);
        const sufijo = String(textoOriginal).slice(numeroEncontrado.index + numeroEncontrado[0].length);

        const inicio = performance.now();

        const animar = (tiempo) => {
            const progreso = limitar((tiempo - inicio) / CONFIG.duracionAnimacion, 0, 1);
            const suavizado = 1 - Math.pow(1 - progreso, 3);
            const valorActual = Math.round(objetivo * suavizado);

            elemento.textContent = `${prefijo}${valorActual}${sufijo}`;

            if (progreso < 1) {
                requestAnimationFrame(animar);
            } else {
                elemento.textContent = `${prefijo}${Math.round(objetivo)}${sufijo}`;
            }
        };

        requestAnimationFrame(animar);
    });
}

    function configurarEstadoVisual() {
        const total = datos.general.total;
        const porcentaje = total > 0 ? Math.round((datos.general.puntuales / total) * 100) : 0;

        document.querySelectorAll('[data-puntualidad]').forEach((elemento) => {
            elemento.textContent = `${porcentaje}%`;
        });

        document.querySelectorAll('[data-total-registros]').forEach((elemento) => {
            elemento.textContent = String(total);
        });
    }

    function configurarRedimension() {
        window.addEventListener('resize', () => {
            clearTimeout(temporizadorRedimension);
            temporizadorRedimension = setTimeout(renderizarGraficos, CONFIG.retrasoRedimension);
        });
    }

    function iniciarDashboard() {
        configurarReloj();
        configurarAnimaciones();
        configurarBrilloInteractivo();
        configurarBotonesSegmentados();
        configurarEstadoVisual();
        animarContadores();
        renderizarGraficos();
        configurarRedimension();
    }

    document.addEventListener('DOMContentLoaded', iniciarDashboard);

    window.bioDashboard = {
        renderizarGraficos,
        datos
    };
})();
