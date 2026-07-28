<?php

// stream_proxy.php

// Función auxiliar para renderizar la página con el modal de error sin romper el diseño
function mostrar_pantalla_error($mensaje_error = "Error - Comuníquese con su proveedor") {
    header("Content-Type: text/html; charset=UTF-8");
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Player Proxy - Error</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="/pVID.css"/>
        <style>
            .modal-alerta-error {
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background-color: rgba(220, 53, 69, 0.95);
                color: #fff;
                padding: 15px 30px;
                border-radius: 8px;
                font-family: Arial, sans-serif;
                font-size: 16px;
                font-weight: bold;
                z-index: 99999;
                box-shadow: 0 4px 15px rgba(0,0,0,0.5);
                text-align: center;
                border: 1px solid #ff8585;
                min-width: 300px;
            }
        </style>
    </head>
    <body style="margin:0; padding:0; background:#000;">
        <div class="modal-alerta-error">
            <i class="fa-solid fa-circle-exclamation" style="margin-right: 10px;"></i>
            <?php echo htmlspecialchars($mensaje_error); ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Validaciones iniciales
if (!isset($_GET['url']) || empty($_GET['url'])) {
    mostrar_pantalla_error();
}

$target_url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
if (!$target_url) {
    mostrar_pantalla_error();
}

$url_parts = parse_url($target_url);
$base_domain = $url_parts['scheme'] . '://' . $url_parts['host'];

$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36\r\n" .
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8\r\n" .
                    "Accept-Language: es-ES,es;q=0.9,en;q=0.8\r\n" .
                    "Referer: " . $base_domain . "/\r\n" . 
                    "Origin: " . $base_domain . "\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($target_url, false, $context);

if ($html === FALSE) {
    mostrar_pantalla_error();
}

// =========================================================================
// VALIDACIÓN DE EXCEPCIÓN: ¿Requiere emulación por Blob/Intercepción activa?
// =========================================================================
$string_excepcion = "var source='https://test-videos.co.uk/vids/bigbuckbunny/mp4/av1/1080/Big_Buck_Bunny_1080_10s_5MB.mp4';";
$forzar_modo_blob = (strpos($html, $string_excepcion) !== false);

// ==========================================
// 1. FILTRADO AGRESIVO DE SCRIPTS DE ANUNCIOS
// ==========================================
$ad_patterns = [
    '/<script[^>]*src=["\'][^"\']*popads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*exoclick[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*juicyads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*propush[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*onclickads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*popunder[^"\']*["\'][^>]*><\/script>/i'
];
$html = preg_replace($ad_patterns, '', $html);

// ==========================================
// 2. REESCRITURA DE RUTAS RELATIVAS REMOTAS
// ==========================================
$html = preg_replace('/(src|href)=["\']\/([^\/\\\\][^"\'>]+)["\']/i', '$1="' . $base_domain . '/$2"', $html);
$html = preg_replace('/(src|href)=["\']\/\/([^"\'>]+)["\']/i', '$1="https://$2"', $html);
$html = str_replace(['"/stream/', '\'/stream/'], ['"' . $base_domain . '/stream/', '\'' . $base_domain . '/stream/'], $html);
$html = str_replace(['"/dl?', '\'/dl?'], ['"' . $base_domain . '/dl?', '\'' . $base_domain . '/dl?'], $html);

$jquery_inject = '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';

// =====================================================
// 3. CAPA INTERCEPTORA JS ANTI-ADS CON PROTECCIÓN EXTRA
// =====================================================
$anti_ads_js = "
<script>
    (function() {
        const ORIGIN_DOMAIN = '" . $base_domain . "';
        let streamEncontrado = false;

        function propagarUrlEncontrada(urlMultimedia) {
            if (streamEncontrado) return;
            streamEncontrado = true;
            console.log('Stream interceptado con éxito:', urlMultimedia);
            
            // Buscar canal de comunicación hacia la ventana principal (si corre en iframe)
            const destino = (window.parent !== window) ? window.parent : window;
            if (destino && typeof destino.inicializarTuPlayerNativo === 'function') {
                destino.inicializarTuPlayerNativo(urlMultimedia);
            }
            
            // Intentar autodestruir el contenedor desde el contexto adecuado
            try {
                const contenedorOculto = parent.document.getElementById('remote-hidden-container');
                if (contenedorOculto) contenedorOculto.remove();
            } catch(e) {}
        }

        // Interceptores XMLHttpRequest
        const openOriginal = window.XMLHttpRequest.prototype.open;
        window.XMLHttpRequest.prototype.open = function(method, url) {
            if (url.startsWith('/') && !url.startsWith('//')) { url = ORIGIN_DOMAIN + url; }
            if (url.includes('.m3u8') || url.includes('.mp4') || url.includes('/stream/') || url.includes('master.m3u8') || url.includes('playlist.m3u8')) {
                propagarUrlEncontrada(url);
            }
            return openOriginal.apply(this, arguments);
        };

        // Interceptores Fetch
        const fetchOriginal = window.fetch;
        window.fetch = function(input, init) {
            let url = (typeof input === 'string') ? input : (input && input.url) ? input.url : '';
            if (url.startsWith('/') && !url.startsWith('//')) { url = ORIGIN_DOMAIN + url; }
            if (url.includes('.m3u8') || url.includes('.mp4') || url.includes('/stream/') || url.includes('master.m3u8') || url.includes('playlist.m3u8')) {
                propagarUrlEncontrada(url);
            }
            return fetchOriginal.apply(this, arguments);
        };

        // Bloqueo total de window.open (Destrucción total de popups por asignación estricta)
        const blockOpen = function() { console.log('Popup bloqueado de forma nativa.'); return null; };
        Object.defineProperty(window, 'open', { value: blockOpen, writable: false, configurable: false });
        Object.defineProperty(document, 'open', { value: blockOpen, writable: false, configurable: false });

        // Bloquear clics fantasmas que generan popups al interactuar con el entorno invisible
        window.addEventListener('click', function(e) {
            if(!streamEncontrado) {
                // Dejar pasar eventos únicamente si van dirigidos al tag de video que autoejecutamos
                if(e.target.tagName !== 'VIDEO') {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        }, true);

        document.addEventListener('DOMContentLoaded', function() {
            let maxIntentos = 100;
            let intento = 0;
            
            const forzarAutoplayOculto = setInterval(function() {
                intento++;
                
                if (typeof jwplayer === 'function' && jwplayer()) {
                    const player = jwplayer();
                    player.setVolume(0);
                    player.setMute(true);
                    player.play();
                    
                    if (player.getPlaylist && player.getPlaylist()[0]) {
                        let fileUrl = player.getPlaylist()[0].file;
                        if (fileUrl) { propagarUrlEncontrada(fileUrl); clearInterval(forzarAutoplayOculto); }
                    }
                }
                
                document.querySelectorAll('video').forEach(video => {
                    video.muted = true;
                    video.volume = 0;
                    video.setAttribute('autoplay', 'true');
                    video.setAttribute('playsinline', 'true');
                    video.play().catch(() => {});
                    if (video.currentSrc) {
                        propagarUrlEncontrada(video.currentSrc);
                        clearInterval(forzarAutoplayOculto);
                    }
                });

                if (intento > maxIntentos || streamEncontrado) {
                    clearInterval(forzarAutoplayOculto);
                }
            }, 250);
        });

        // Limpieza de avisos flotantes molestos
        setInterval(function() {
            document.querySelectorAll('a[target=\"_blank\"]').forEach(el => el.setAttribute('target', '_self'));
            ['adb-notice', '#adb-notice', 'div[style*=\"z-index: 2147483647\"]'].forEach(sel => {
                document.querySelectorAll(sel).forEach(el => el.remove());
            });
        }, 400);
    })();
</script>
";

$css_bypass = "
<style>
    div[id*='adblock'], div[class*='adblock'], .adblock_box, #play-block, #blocking-ui,
    div[style*='position: fixed'][style*='z-index:'], .mgbox, #popunder {
        display: none !important;
        visibility: hidden !important;
    }
</style>
";

if (strpos($html, '<head>') !== false) {
    $html = str_replace('<head>', '<head>' . $jquery_inject . $anti_ads_js . $css_bypass, $html);
} else {
    $html = $jquery_inject . $anti_ads_js . $css_bypass . $html;
}

// FUNCIÓN PARA DESOFUSCAR 'PACKER'
function unpacker($js) {
    if (!preg_match('/eval\(function\(p,a,c,k,e,d\).*?\}\(\'(.*?)\'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\'(.*?)\'\.split\(\'\|\'\)/s', $js, $matches)) {
        return $js;
    }
    $p = $matches[1]; $a = (int)$matches[2]; $c = (int)$matches[3]; $k = explode('|', $matches[4]);
    while ($c--) { if (!empty($k[$c])) { $p = preg_replace_with_boundaries($c, $k[$c], $a, $p); } }
    return $p;
}
function preg_replace_with_boundaries($index, $word, $base, $source) {
    $search = base_convert_string($index, $base);
    return preg_replace('/\b' . preg_quote($search, '/') . '\b/', $word, $source);
}
function base_convert_string($num, $base) {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($base <= 36) return base_convert($num, 10, $base);
    $res = ''; while ($num > 0) { $res = $chars[$num % $base] . $res; $num = intval($num / $base); }
    return $res == '' ? '0' : $res;
}

if (strpos($html, 'eval(function(p,a,c,k,e,d)') !== false) {
    $html .= "\n/* --- Bloque Desofuscado --- */\n" . unpacker($html);
}

// Búsqueda estática inicial por expresiones regulares en PHP
$video_urls = [];
$subtitles_urls = [];
$html_normalized = preg_replace('/1d:\/\//i', 'https://', $html);

if (preg_match_all('/<(?:source|video)\b[^>]*src=["\']([^"\']+)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}
if (preg_match_all('/<track\b[^>]*src=["\']([^"\']+)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $subtitles_urls[] = $url; }
}
if (preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:mp4|m3u8|mpd|webm)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}
if (preg_match_all('/["\'](https?:\/\/[^"\']+\.(?:vtt|srt)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $subtitles_urls[] = $url; }
}
if (preg_match_all('/(?:file|src|source|\"1o\"|\"1c\"|\"1g\")\s*[:=]\s*["\']([^"\']+\.(?:mp4|m3u8|mpd|webm)[^"\']*)["\']/i', $html_normalized, $matches)) {
    foreach ($matches[1] as $url) { $video_urls[] = $url; }
}

function clean_proxied_urls($urls_array, $base_domain) {
    $urls_array = array_unique($urls_array); $cleaned = [];
    foreach ($urls_array as $url) {
        $url = stripslashes(trim($url));
        if (strpos($url, '//') === 0) { $url = 'https:' . $url; } 
        elseif (strpos($url, '/') === 0) { $url = $base_domain . $url; }
        if (filter_var($url, FILTER_VALIDATE_URL)) { $cleaned[] = $url; }
    }
    return array_values($cleaned);
}

$final_urls = clean_proxied_urls($video_urls, $base_domain);
$final_subs = clean_proxied_urls($subtitles_urls, $base_domain);

$currentStreamUrl = (!empty($final_urls) && !$forzar_modo_blob) ? $final_urls[0] : '';

header("Content-Type: text/html; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Credentials: true");
header("Permissions-Policy: encrypted-media=*, autoplay=*, fullscreen=*");
header("Referrer-Policy: no-referrer-when-downgrade");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Player Proxy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/pVID.css"/>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script type="text/javascript" src="/scrolltv.js"></script>
    <style>
        /* El contenedor ahora aloja un iframe blindado */
        #remote-hidden-container {
            position: absolute;
            top: -9999px;
            left: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: -1;
        }
        #iframe-bloqueador {
            width: 100%;
            height: 100%;
            border: none;
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#000;">

    <!-- PLAYER NATIVO Principal -->
    <div class="player-container" id="playerContainer" style="display: none;">
        <video id="myVideo" playsinline preload="metadata">
            <?php 
            foreach ($final_subs as $index => $sub_url) {
                $label = ($index === 0) ? "Español (External)" : "Subtítulo " . ($index + 1);
                echo '<track src="' . htmlspecialchars($sub_url) . '" kind="subtitles" srclang="es" label="' . htmlspecialchars($label) . '">';
            }
            ?>
        </video>
        <div class="toast-notification" id="toastHUD">Audio: Español</div>
        <div class="loader" id="spinner"></div>
        <button class="center-play-btn" id="playBtn">
            <i class="fa-solid fa-play" style="margin-left: 4px;"></i>
        </button>
        <div class="controls-overlay" id="controlsOverlay">
            <div class="time-display">
                <span id="currentTime">00:00:00</span>
                <span id="duration">00:00:00</span>
            </div>
            <div class="progress-container" id="progressContainer" tabindex="0">
                <div class="progressBar" id="progressBar"></div>
            </div>
            <div class="bottom-buttons">
                <div class="left-buttons">
                    <button class="btn-ctrl" id="btnBack"><i class="fa-solid fa-arrow-rotate-left"></i></button>
                    <button class="btn-ctrl" id="btnForward"><i class="fa-solid fa-arrow-rotate-right"></i></button>
                </div>
                <div class="right-buttons">
                    <button class="btn-ctrl" id="audioBtn"><i class="fa-solid fa-headphones"></i></button>
                    <button class="btn-ctrl" id="ccBtn">cc</button>
                    <button class="btn-ctrl" id="aspectBtn"><i class="fa-solid fa-expand"></i></button>
                    <button class="quality-btn-badge" id="qualityBtn">AUTO</button>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENEDOR SEGURO CONTRA POPUPS/ADS -->
    <div id="remote-hidden-container">
        <?php 
        if (empty($currentStreamUrl)) {
            // Se encapsula el HTML remoto dentro de un iframe blindado sin permisos de popup.
            // allow-scripts y allow-same-origin permiten la ejecución del interceptor sin romper políticas de origen.
            echo '<iframe id="iframe-bloqueador" sandbox="allow-scripts allow-same-origin"></iframe>';
        }
        ?>
    </div>

    <script src="/pVID.js"></script>
    <script type="text/javascript">
        let phpExtractedUrl = "<?php echo $currentStreamUrl; ?>";
        let htmlRemotoParaIframe = <?php echo empty($currentStreamUrl) ? json_encode($html) : '""'; ?>;

        window.inicializarTuPlayerNativo = function(urlFinalStream) {
            if (!urlFinalStream) return;
            
            const nativePlayerBox = document.getElementById('playerContainer');
            if (nativePlayerBox) {
                nativePlayerBox.style.display = 'block';
            }

            if (typeof window.playCustomVideo === 'function') {
                window.playCustomVideo(urlFinalStream);
            } else {
                const videoTag = document.getElementById('myVideo');
                if (videoTag) {
                    if (urlFinalStream.includes('.m3u8') && typeof Hls !== 'undefined' && Hls.isSupported()) {
                        const hls = new Hls();
                        hls.loadSource(urlFinalStream);
                        hls.attachMedia(videoTag);
                    } else {
                        videoTag.src = urlFinalStream;
                    }
                    videoTag.play().catch(e => console.log("Interacción requerida"));
                }
            }
        };

        document.addEventListener("DOMContentLoaded", function() {
            if (phpExtractedUrl !== "") {
                window.inicializarTuPlayerNativo(phpExtractedUrl);
                const envRemoto = document.getElementById('remote-hidden-container');
                if (envRemoto) envRemoto.remove();
            } else if (htmlRemotoParaIframe !== "") {
                // Escribir el HTML de manera segura dentro del iframe bajo el entorno controlado por sandbox
                const iframe = document.getElementById('iframe-bloqueador');
                if (iframe) {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    doc.open();
                    doc.write(htmlRemotoParaIframe);
                    doc.close();
                }

                // Timeout de seguridad en caso de fallo
                setTimeout(function() {
                    const playerVisible = document.getElementById('playerContainer').style.display;
                    if (playerVisible === 'none' || playerVisible === '') {
                        alert("Error - No se pudo capturar el flujo multimedia.");
                    }
                }, 15000);
            }
        });
    </script>
</body>
</html>
