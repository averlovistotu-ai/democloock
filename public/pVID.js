// Selección de elementos del DOM del reproductor personalizado
const video = document.getElementById('myVideo');
const playerContainer = document.getElementById('playerContainer');
const controlsOverlay = document.getElementById('controlsOverlay');
const playBtn = document.getElementById('playBtn');
const playIcon = playBtn ? playBtn.querySelector('i') : null;
const spinner = document.getElementById('spinner');
const progressContainer = document.getElementById('progressContainer');
const progressBar = document.getElementById('progressBar');
const currentTimeTxt = document.getElementById('currentTime');
const durationTxt = document.getElementById('duration');
const btnBack = document.getElementById('btnBack');
const btnForward = document.getElementById('btnForward');
const toastHUD = document.getElementById('toastHUD');
const audioBtn = document.getElementById('audioBtn');
const ccBtn = document.getElementById('ccBtn');
const ratioBtn = document.getElementById('ratioBtn');
const qualityBtn = document.getElementById('qualityBtn');

let hlsInstance = null;
let toastTimeout = null;
let inactivityTimeout = null;

let availableQualities = [];
let currentQualityIndex = 0;
let availableAudios = [];
let currentAudioIndex = 0;
let availableCC = [];
let aspectModes = ["cover", "contain", "fill"];
let aspectIndex = 0;
let currentCCIndex = 0;
let tempProgressTime = null; // Guarda el tiempo temporal mientras usas las flechas

// ==========================================
//  FUNCIÓN PRINCIPAL: CARGAR Y REPRODUCIR URL
// ==========================================
window.playCustomVideo = function(rawUrl) {
    // ELIMINADO: Ya no se altera el aria-hidden ni el tabindex del body ni de sus hijos.

    // Darle el foco inicial al botón central de play al abrir de forma directa
    setTimeout(() => { if(playBtn) playBtn.focus(); }, 100);

    if (!rawUrl) return;

    // 1. Mostrar el contenedor del reproductor
    playerContainer.style.display = "block";

    // 2. Limpiar y destruir instancias HLS previas
    if (hlsInstance) {
        hlsInstance.destroy();
        hlsInstance = null;
    }
    video.pause();
    video.src = "";
    video.load();

    // Resetear contadores de pistas
    availableQualities = [];
    availableAudios = [];
    availableCC = [];

    const isHLS = rawUrl.toLowerCase().includes('.m3u8') || rawUrl.toLowerCase().includes('/hls');

    if (isHLS) {
        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
            hlsInstance = new Hls();
            hlsInstance.loadSource(rawUrl);
            hlsInstance.attachMedia(video);
            
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
                parseHLSQualities();
            });

            hlsInstance.on(Hls.Events.AUDIO_TRACKS_UPDATED, function() {
                parseHLSAudios();
            });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = rawUrl;
        }
    } else {
        video.src = rawUrl;
        video.onloadedmetadata = null;
        video.onloadedmetadata = () => {
            parseNativeAudios();
            parseNativeCC();
            if (durationTxt) durationTxt.textContent = formatTime(video.duration);
        };
    }

    // 3. Forzar Pantalla Completa
    if (playerContainer.requestFullscreen) {
        playerContainer.requestFullscreen().catch(() => {});
    } else if (playerContainer.webkitRequestFullscreen) {
        playerContainer.webkitRequestFullscreen().catch(() => {});
    }

    if (screen.orientation && screen.orientation.lock) {
        screen.orientation.lock('landscape').catch(() => {});
    }

    // 4. Iniciar reproducción
    video.load();
    video.play()
        .then(() => { if (playIcon) playIcon.className = "fa-solid fa-pause"; })
        .catch(error => console.log("Error de auto-play: ", error));

    resetInactivityTimer();
};

/**
 * ÚNICA FUNCIÓN DE LIMPIEZA Y SALIDA DEL REPRODUCTOR
 */
window.closeCustomVideo = function() {
    if (screen.orientation && screen.orientation.unlock) {
        screen.orientation.unlock();
    }

    video.pause();
    playerContainer.style.display = 'none';

    // Si aún estamos en pantalla completa, salimos elegantemente
    const fsElement = document.fullscreenElement || document.webkitFullscreenElement;
    if (fsElement) {
        if (document.exitFullscreen) document.exitFullscreen().catch(() => {});
        else if (document.webkitExitFullscreen) document.webkitExitFullscreen().catch(() => {});
    }

    if (hlsInstance) {
        hlsInstance.destroy();
        hlsInstance = null;
    }

    // Devolver el foco de forma limpia a la tarjeta que abrió el video sin interferencias
    if (typeof tarjetaActivaGlobal !== 'undefined' && tarjetaActivaGlobal) {
        setTimeout(() => {
            tarjetaActivaGlobal.focus();
        }, 100);
    }
};

// Escucha única para detectar la salida de pantalla completa (Tecla ESC o botones del navegador)
function checkFullscreenExit() {
    const fsElement = document.fullscreenElement || document.webkitFullscreenElement;
    // Si el usuario sale de pantalla completa, disparamos el cierre unificado
    if (!fsElement && playerContainer.style.display === "block") {
        window.closeCustomVideo();
    }
}

// Limpiamos los listeners duplicados y dejamos una sola declaración limpia
document.addEventListener('fullscreenchange', checkFullscreenExit);
document.addEventListener('webkitfullscreenchange', checkFullscreenExit);

// ==========================================
//  MÁQUINA DE INACTIVIDAD (OCULTAR CONTROLES)
// ==========================================
function resetInactivityTimer() {
    if (!controlsOverlay || !playBtn || !playerContainer) return;
    
    // Mostrar controles y permitir interactuar
    controlsOverlay.classList.remove('hide-controls');
    playBtn.classList.remove('hide-controls');
    playerContainer.classList.remove('hide-cursor');
    controlsOverlay.style.pointerEvents = "auto"; 

    clearTimeout(inactivityTimeout);

    if (!video.paused) {
        inactivityTimeout = setTimeout(() => {
            controlsOverlay.classList.add('hide-controls');
            playBtn.classList.add('hide-controls');
            playerContainer.classList.add('hide-cursor');
            
            // Bloquea clics accidentales y quita el foco activo para evitar bugs de teclado
            controlsOverlay.style.pointerEvents = "none"; 
            if (document.activeElement && document.activeElement !== document.body) {
                document.activeElement.blur();
            }
        }, 3000);
    }
}

// Disparadores por movimiento de botones, mouse, táctil y teclado
if (playerContainer) {
    playerContainer.addEventListener('mousemove', resetInactivityTimer);
    playerContainer.addEventListener('click', resetInactivityTimer);
    playerContainer.addEventListener('touchstart', resetInactivityTimer, {passive: true});
    playerContainer.addEventListener('keydown', resetInactivityTimer); // Teclado despierta el HUD
}
video.addEventListener('playing', resetInactivityTimer);
video.addEventListener('pause', resetInactivityTimer);

// ==========================================
//  SISTEMA HUD (NOTIFICACIONES EN PANTALLA)
// ==========================================
function showHUD(text) {
    if (!toastHUD) return;
    clearTimeout(toastTimeout);
    toastHUD.textContent = text;
    toastHUD.classList.add('show');
    toastTimeout = setTimeout(() => {
        toastHUD.classList.remove('show');
    }, 2000);
}

function getQualityBadgeName(name) {
    if (!name || name.toLowerCase() === 'auto') return 'AUTO';
    const res = parseInt(name.replace(/\D/g, ''));
    if (res <= 144) return 'LOW';
    if (res <= 240) return 'LQ';
    if (res <= 360) return 'MQ';
    if (res <= 480) return 'SD';
    if (res <= 720) return 'HD';
    if (res <= 1080) return 'FHD';
    if (res <= 1440) return 'QHD';
    if (res <= 2160) return 'UHD';
    return name;
}

// 1. SEÑAL / CALIDAD
function parseHLSQualities() {
    if (!hlsInstance || !qualityBtn) return;
    availableQualities = [{ id: -1, name: 'Auto' }];
    hlsInstance.levels.forEach((level, idx) => {
        availableQualities.push({
            id: idx,
            name: level.name ? level.name : `${level.height}p`
        });
    });
    currentQualityIndex = 0;
    qualityBtn.textContent = getQualityBadgeName(availableQualities[currentQualityIndex].name);
}

if (qualityBtn) {
    qualityBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHLS = video.src.toLowerCase().includes('.m3u8') || (hlsInstance !== null);
        if (!isHLS || availableQualities.length <= 1) {
            qualityBtn.textContent = 'SD';
            return;
        }
        currentQualityIndex = (currentQualityIndex + 1) % availableQualities.length;
        const targetQuality = availableQualities[currentQualityIndex];
        hlsInstance.currentLevel = targetQuality.id;
        qualityBtn.textContent = getQualityBadgeName(targetQuality.name);
    });
}

// 2. AUDIO TRACKS
function parseHLSAudios() {
    if (!hlsInstance) return;
    availableAudios = hlsInstance.audioTracks.map((track, idx) => ({
        id: idx,
        name: track.name || track.lang || `Audio ${idx + 1}`
    }));
    currentAudioIndex = hlsInstance.audioTrack;
}

function parseNativeAudios() {
    availableAudios = [];
    if (video.audioTracks && video.audioTracks.length > 0) {
        for (let i = 0; i < video.audioTracks.length; i++) {
            availableAudios.push({
                id: i,
                name: video.audioTracks[i].label || video.audioTracks[i].language || `Audio ${i + 1}`
            });
            if (video.audioTracks[i].enabled) currentAudioIndex = i;
        }
    }
}

if (audioBtn) {
    audioBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (availableAudios.length <= 1) {
            showHUD("Audio: No Disponible");
            return;
        }
        currentAudioIndex = (currentAudioIndex + 1) % availableAudios.length;
        const targetAudio = availableAudios[currentAudioIndex];
        const isHLS = hlsInstance !== null;

        if (isHLS && hlsInstance) {
            hlsInstance.audioTrack = targetAudio.id;
        } else if (video.audioTracks) {
            for (let i = 0; i < video.audioTracks.length; i++) {
                video.audioTracks[i].enabled = (i === targetAudio.id);
            }
        }
        showHUD(`Audio: ${targetAudio.name}`);
    });
}

// 3. SUBTÍTULOS (CC)
function parseNativeCC() {
    availableCC = [{ id: -1, name: 'Desactivados' }];
    if (video.textTracks && video.textTracks.length > 0) {
        for (let i = 0; i < video.textTracks.length; i++) {
            availableCC.push({
                id: i,
                name: video.textTracks[i].label || video.textTracks[i].language || `Subtítulo ${i + 1}`
            });
        }
    }
    currentCCIndex = 0; 
}

if (ccBtn) {
    ccBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHLS = hlsInstance !== null;
        if (!isHLS && availableCC.length <= 1) parseNativeCC(); 

        if (availableCC.length <= 1) {
            showHUD("Subtítulos: No Disponible");
            return;
        }

        currentCCIndex = (currentCCIndex + 1) % availableCC.length;
        const targetCC = availableCC[currentCCIndex];

        if (targetCC.id === -1) {
            for (let i = 0; i < video.textTracks.length; i++) {
                video.textTracks[i].mode = 'disabled';
            }
            showHUD("Subtítulos: Desactivados");
        } else {
            for (let i = 0; i < video.textTracks.length; i++) {
                video.textTracks[i].mode = (i === targetCC.id) ? 'showing' : 'disabled';
            }
            showHUD(`Subtítulos: ${targetCC.name}`);
        }
    });
}

// ==========================================
//  CONTROLES ESTÁNDAR DE PLAY/PAUSE/TIME
// ==========================================
if (playBtn) playBtn.addEventListener('click', togglePlay);


function togglePlay() {
    if (video.paused) {
        video.play().catch(error => console.log(error));
        if (playIcon) playIcon.className = "fa-solid fa-pause";
    } else {
        video.pause();
        if (playIcon) playIcon.className = "fa-solid fa-play";
    }
    resetInactivityTimer();
}

if (spinner) {
    video.addEventListener('waiting', () => spinner.style.display = 'flex');
    video.addEventListener('playing', () => spinner.style.display = 'none');
    video.addEventListener('seeking', () => spinner.style.display = 'flex');
    video.addEventListener('seeked', () => spinner.style.display = 'none');
    video.addEventListener('canplay', () => spinner.style.display = 'none');
}

video.addEventListener('timeupdate', () => {
    // Solo actualiza visualmente si el usuario NO está moviendo la barra con las flechas
    if (video.duration && progressBar && tempProgressTime === null) {
        const percentage = (video.currentTime / video.duration) * 100;
        progressBar.style.width = `${percentage}%`;
    }
    
    // El texto del tiempo actual cambia con el video si no estamos en modo "búsqueda"
    if (currentTimeTxt && tempProgressTime === null) {
        currentTimeTxt.textContent = formatTime(video.currentTime);
    }
});

video.addEventListener('loadedmetadata', () => {
    if (durationTxt) durationTxt.textContent = formatTime(video.duration);
});

function formatTime(seconds) {
    if (isNaN(seconds) || seconds === Infinity) return "00:00:00";
    const hrs = Math.floor(seconds / 3600).toString().padStart(2, '0');
    const mins = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${hrs}:${mins}:${secs}`;
}

if (progressContainer) {
    // Manejo de teclado específico para la barra de progreso
    progressContainer.addEventListener('keydown', (e) => {
        if (!video.duration) return;

        // Si no se ha iniciado la búsqueda temporal, partimos del tiempo actual del video
        if (tempProgressTime === null) {
            tempProgressTime = video.currentTime;
        }

        const step = 10; // Segundos que avanza/retrocede por pulsación

        if (e.key === 'ArrowRight') {
            e.preventDefault();
            e.stopPropagation(); // Evitamos que scrollTV mueva el foco
            resetInactivityTimer();

            tempProgressTime = Math.min(video.duration, tempProgressTime + step);
            
            // Actualización puramente VISUAL
            const percentage = (tempProgressTime / video.duration) * 100;
            progressBar.style.width = `${percentage}%`;
            if (currentTimeTxt) currentTimeTxt.textContent = formatTime(tempProgressTime);

        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            e.stopPropagation(); // Evitamos que scrollTV mueva el foco
            resetInactivityTimer();

            tempProgressTime = Math.max(0, tempProgressTime - step);
            
            // Actualización puramente VISUAL
            const percentage = (tempProgressTime / video.duration) * 100;
            progressBar.style.width = `${percentage}%`;
            if (currentTimeTxt) currentTimeTxt.textContent = formatTime(tempProgressTime);

        } else if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            resetInactivityTimer();

            // AQUÍ SE AJUSTA EL VIDEO REALMENTE
            if (tempProgressTime !== null) {
                video.currentTime = tempProgressTime;
                tempProgressTime = null; // Liberamos el estado temporal
            }
        }
    });

    // Tu evento 'blur' existente restaurará todo si el usuario cambia de botón sin presionar Enter
    progressContainer.addEventListener('blur', () => {
        tempProgressTime = null; 
        if (video.duration) {
            const percentage = (video.currentTime / video.duration) * 100;
            progressBar.style.width = `${percentage}%`;
            if (currentTimeTxt) currentTimeTxt.textContent = formatTime(video.currentTime);
        }
    });
    
    // Tu evento 'click' existente...
    progressContainer.addEventListener('click', (e) => {
        const rect = progressContainer.getBoundingClientRect();
        const clickX = e.clientX - rect.left;
        const percentage = (clickX / rect.width) * 100;
        progressBar.style.width = percentage + '%';
        video.currentTime = (clickX / rect.width) * video.duration;
        tempProgressTime = null;
        resetInactivityTimer();
    });
}

if (btnBack) {
    btnBack.addEventListener('click', (e) => { 
        e.stopPropagation(); 
        video.currentTime = Math.max(0, video.currentTime - 10); 
    });
}
if (btnForward) {
    btnForward.addEventListener('click', (e) => { 
        e.stopPropagation(); 
        video.currentTime = Math.min(video.duration, video.currentTime + 10); 
    });
}

document.addEventListener('contextmenu', e => e.preventDefault());

const aspectBtn = document.getElementById('aspectBtn');

if (aspectBtn) {
    aspectBtn.addEventListener('click', (e) => {

        e.stopPropagation();

        aspectIndex++;

        if (aspectIndex >= aspectModes.length) {
            aspectIndex = 0;
        }

        video.style.objectFit = aspectModes[aspectIndex];

    });
}


// CONTROL DE DESPERTAR CONTROLES Y FORZAR FOCUS CON TECLADO
document.addEventListener('keydown', (e) => {
    // Solo actuar si el reproductor de video está visible en pantalla
    if (!playerContainer || playerContainer.style.display !== "block") return;

    // Si los controles están ocultos o el foco está perdido en el vacío (body)
    const controlsAreHidden = controlsOverlay && controlsOverlay.classList.contains('hide-controls');
    const bodyHasFocus = document.activeElement === document.body || !document.activeElement;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        
        // 1. Despertamos los controles inmediatamente
        resetInactivityTimer();

        // 2. Si el usuario presionó ABAJO y no hay nada enfocado (o el HUD estaba oculto)
        if (e.key === 'ArrowDown' && (controlsAreHidden || bodyHasFocus)) {
            e.preventDefault();
            e.stopPropagation(); // Detiene el comportamiento geométrico temporalmente para rescatar el foco

            // Forzamos el foco en el elemento clave que quieras (por ejemplo, el botón de Play)
            if (playBtn) {
                playBtn.focus();
            } else if (progressContainer) {
                progressContainer.focus();
            }
            return;
        }
    }
}, true); // Usamos 'true' (capturing) para asegurarnos de que este evento se ejecute ANTES que tu ScrollTV