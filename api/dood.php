<?php
// 1. MODO PROXY (PROCESAR Y TUNELIZAR EL VIDEO)
if (isset($_GET['proxy_url'])) {
    $videoUrl = base64_decode($_GET['proxy_url']);
    
    if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        header("HTTP/1.1 400 Bad Request");
        die("URL inválida.");
    }

    $urlParts = parse_url($videoUrl);
    $referer = $urlParts['scheme'] . '://' . $urlParts['host'] . '/';

    $options = [
        "http" => [
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                        "Referer: " . $referer . "\r\n" . 
                        "Origin: " . rtrim($referer, '/') . "\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    $content = @file_get_contents($videoUrl, false, $context);

    if ($content === false) {
        header("HTTP/1.1 404 Not Found");
        die("Error al obtener fragmento.");
    }

    if (strpos($videoUrl, '.m3u8') !== false || strpos($content, '#EXTM3U') !== false) {
        header("Content-Type: application/x-mpegURL");
        header("Access-Control-Allow-Origin: *"); 
        
        $baseVideoDir = dirname($videoUrl) . '/';
        $lines = explode("\n", $content);
        
        foreach ($lines as &$line) {
            $line = trim($line);
            if (!empty($line) && $line[0] !== '#') {
                if (strpos($line, 'http') !== 0) {
                    $line = $baseVideoDir . $line;
                }
                $currentScript = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
                $line = $currentScript . "?proxy_url=" . base64_encode($line);
            }
        }
        echo implode("\n", $lines);
    } else {
        header("Content-Type: video/MP2T");
        header("Access-Control-Allow-Origin: *");
        echo $content;
    }
    exit;
}

// 2. MODO FILTRO (CARGAR Y LIMPIAR LA PÁGINA)
if (!isset($_GET['url']) || empty($_GET['url'])) {
    die("Error: Agrega ?url= al final de la dirección.");
}

$urlOriginal = $_GET['url'];
if (!filter_var($urlOriginal, FILTER_VALIDATE_URL)) {
    die("Error: URL inválida.");
}

$urlParts = parse_url($urlOriginal);
$baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'];

$options = [
    "http" => [
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($urlOriginal, false, $context);

if ($html === false) {
    die("Error: No se pudo obtener la página original.");
}

// ==========================================================
// FILTRADO AGRESIVO ANTES DE CARGAR EL DOM
$filtrosPublicidad = [
    '/<script[^>]*src=["\'][^"\']*popads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*exoclick[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*juicyads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*propush[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*onclickads[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*popunder[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*adserv[^"\']*["\'][^>]*><\/script>/i',
    '/<script[^>]*src=["\'][^"\']*adsystem[^"\']*["\'][^>]*><\/script>/i',
    '/window\.open\s*\(/i' 
];
$html = preg_replace($filtrosPublicidad, '', $html);
// ==========================================================

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
$xpath = new DOMXPath($doc);

// --- 3. ESCUDO ABSOLUTO + MODAL DE ENLACE M3U8 ---
$head = $doc->getElementsByTagName('head')->item(0);
if ($head) {
    $styleBlock = $doc->createElement('style');
    $styleBlock->textContent = "
        iframe[style*='position: fixed'][style*='z-index: 2147483645'],
        iframe[style*='inset: auto 0px 0px auto'],
        div[style*='position: fixed'][style*='z-index: 214748364'],
        [class*='ad-'], [class*='banner-'], [id*='pop-'] {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        video, .video-player, #vplayer, .jwplayer, .plyr {
            pointer-events: auto !important;
            cursor: pointer !important;
        }
        
        /* ESTILOS DEL MODAL */
        #modal-m3u8-container {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999999999;
            background: #18181b;
            color: #ffffff;
            border: 1px solid #27272a;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 420px;
            width: calc(100% - 40px);
            animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp {
            from { transform: translateY(100px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        #modal-m3u8-container h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #10b981;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #modal-m3u8-container .close-btn {
            background: none;
            border: none;
            color: #a1a1aa;
            font-size: 18px;
            cursor: pointer;
            padding: 0 4px;
        }
        #modal-m3u8-container .input-box {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        #modal-m3u8-container input {
            flex: 1;
            background: #09090b;
            border: 1px solid #3f3f46;
            color: #e4e4e7;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
        }
        #modal-m3u8-container button.copy-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        #modal-m3u8-container button.copy-btn:hover {
            background: #1d4ed8;
        }
    ";
    $head->appendChild($styleBlock);

    $interceptorScript = $doc->createElement('script');
    $currentScriptUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

    $interceptorScript->textContent = "
        let m3u8UrlDetectada = '';
        let modalMostrado = false;

        // Crear interfaz HTML del modal
        function crearModalUI() {
            if (document.getElementById('modal-m3u8-container')) return;
            const modal = document.createElement('div');
            modal.id = 'modal-m3u8-container';
            modal.innerHTML = `
                <h4>
                    <span>Enlace M3U8/HLS Detectado</span>
                    <button class=\"close-btn\" onclick=\"document.getElementById('modal-m3u8-container').style.display='none'\">&times;</button>
                </h4>
                <p style=\"margin:0; font-size: 11px; color: #a1a1aa;\">Copiar enlace directo extraído:</p>
                <div class=\"input-box\">
                    <input type=\"text\" id=\"input-m3u8-link\" readonly value=\"\" />
                    <button class=\"copy-btn\" id=\"btn-copiar-m3u8\">Copiar</button>
                </div>
            `;
            document.body.appendChild(modal);

            document.getElementById('btn-copiar-m3u8').addEventListener('click', function() {
                const input = document.getElementById('input-m3u8-link');
                input.select();
                navigator.clipboard.writeText(input.value).then(() => {
                    this.textContent = '¡Copiado!';
                    this.style.background = '#059669';
                    setTimeout(() => {
                        this.textContent = 'Copiar';
                        this.style.background = '#2563eb';
                    }, 2000);
                });
            });
        }

        function mostrarModalConUrl(url) {
            if (!url || modalMostrado) return;
            modalMostrado = true;
            crearModalUI();
            
            const input = document.getElementById('input-m3u8-link');
            const modal = document.getElementById('modal-m3u8-container');
            if (input && modal) {
                input.value = url;
                modal.style.display = 'block';
            }
        }

        // 1. Bloqueo de Popups
        const de_nada = function() { return { focus: function(){} }; };
        window.open = de_nada;
        Object.defineProperty(window, 'open', { value: de_nada, writable: false, configurable: false });

        // 2. Interceptación de XHR & Fetch para capturar URL del .m3u8
        var proxyScriptPHP = '{$currentScriptUrl}';
        
        window.XMLHttpRequest.prototype.open_bak = window.XMLHttpRequest.prototype.open;
        window.XMLHttpRequest.prototype.open = function(method, url, async, user, pass) {
            if (typeof url === 'string' && (url.includes('.m3u8') || url.includes('/hls/'))) {
                let cleanUrl = url;
                if (url.includes('proxy_url=')) {
                    cleanUrl = atob(url.split('proxy_url=')[1]);
                }
                m3u8UrlDetectada = cleanUrl;
                url = proxyScriptPHP + '?proxy_url=' + btoa(cleanUrl);
            }
            return this.open_bak(method, url, async, user, pass);
        };

        const originalFetch = window.fetch;
        window.fetch = async function(...args) {
            let resource = args[0];
            let options = args[1] || {};
            if (typeof resource === 'string' && (resource.includes('.m3u8') || resource.includes('/hls/'))) {
                let cleanUrl = resource;
                if (resource.includes('proxy_url=')) {
                    cleanUrl = atob(resource.split('proxy_url=')[1]);
                }
                m3u8UrlDetectada = cleanUrl;
                resource = proxyScriptPHP + '?proxy_url=' + btoa(cleanUrl);
            }
            return originalFetch(resource, options);
        };

        // 3. Control de Autoplay y Detección de Reproducción
        function vincularEventosVideo() {
            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                if (!video.hasAttribute('data-modal-escuchando')) {
                    video.setAttribute('data-modal-escuchando', 'true');
                    
                    // Cuando el video realmente empiece a reproducirse:
                    video.addEventListener('playing', () => {
                        let streamUrl = m3u8UrlDetectada || video.currentSrc || video.src;
                        if (streamUrl.includes('proxy_url=')) {
                            streamUrl = atob(streamUrl.split('proxy_url=')[1]);
                        }
                        mostrarModalConUrl(streamUrl);
                    });
                }
            });

            // En caso de usar la API de JWPlayer directamente
            if (typeof jwplayer === 'function') {
                try {
                    const players = document.querySelectorAll('.jwplayer');
                    players.forEach(p => {
                        const jwInst = jwplayer(p.id);
                        if (jwInst && jwInst.on) {
                            jwInst.on('play', () => {
                                let item = jwInst.getPlaylistItem ? jwInst.getPlaylistItem() : null;
                                let streamUrl = m3u8UrlDetectada || (item ? item.file : '');
                                mostrarModalConUrl(streamUrl);
                            });
                        }
                    });
                } catch(e){}
            }
        }

        function autoPlayForzado() {
            if (typeof jwplayer === 'function') {
                try {
                    const players = document.querySelectorAll('.jwplayer');
                    players.forEach(p => {
                        const jwInst = jwplayer(p.id);
                        if (jwInst && jwInst.getState && jwInst.getState() !== 'playing') {
                            jwInst.setMute(true);
                            jwInst.play(true);
                        }
                    });
                } catch(e){}
            }

            const jwPlayBtns = document.querySelectorAll('.jw-display-icon-display, .jw-icon-play');
            jwPlayBtns.forEach(btn => {
                if (btn && btn.offsetParent !== null) btn.click();
            });

            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                video.setAttribute('autoplay', 'true');
                video.setAttribute('playsinline', 'true');
                video.muted = true;
                if (video.paused) {
                    video.play().catch(()=>{});
                }
            });
        }

        // Clic global para quitar silencio y asegurar reproducción
        document.addEventListener('click', () => {
            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                video.muted = false;
                if (video.paused) video.play().catch(()=>{});
            });
        }, true);

        // Inicialización tras cargar el DOM
        document.addEventListener('DOMContentLoaded', () => {
            crearModalUI();
            vincularEventosVideo();
            autoPlayForzado();

            let intentos = 0;
            const interval = setInterval(() => {
                vincularEventosVideo();
                autoPlayForzado();
                intentos++;
                if (intentos > 10) clearInterval(interval);
            }, 500);
        });
    ";
    
    if ($head->firstChild) {
        $head->insertBefore($interceptorScript, $head->firstChild);
    } else {
        $head->appendChild($interceptorScript);
    }
}

// --- 4. Forzar rutas absolutas ---
$scripts = $doc->getElementsByTagName('script');
$links = $doc->getElementsByTagName('link');
$iframes = $doc->getElementsByTagName('iframe');

foreach ($scripts as $script) {
    if ($script->hasAttribute('src')) {
        $src = $script->getAttribute('src');
        if (strpos($src, '/') === 0 && strpos($src, '//') !== 0) { $script->setAttribute('src', $baseUrl . $src); }
    }
}
foreach ($links as $link) {
    if ($link->hasAttribute('href')) {
        $href = $link->getAttribute('href');
        if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) { $link->setAttribute('href', $baseUrl . $href); }
    }
}

// --- 5. Purga física inmediata ---
$nodesToDelete = [];
foreach ($scripts as $script) {
    $src = $script->getAttribute('src');
    if (
        strpos($src, 'yandex.ru') !== false || 
        strpos($src, 'googletagmanager.com') !== false || 
        strpos($src, '/ad') !== false || 
        strpos($src, 'localstorage-slim') !== false
    ) {
        $nodesToDelete[] = $script;
    }
}

foreach ($iframes as $iframe) {
    $nodesToDelete[] = $iframe; 
}

foreach ($nodesToDelete as $node) {
    if ($node->parentNode) { $node->parentNode->removeChild($node); }
}

libxml_clear_errors();
echo $doc->saveHTML();
?>