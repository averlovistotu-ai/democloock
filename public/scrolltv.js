// --- HISTORIAL DE CONTENEDORES Y MEMORIA DE FOCO ---
let lastFocusedElements = {
  'content-container': null,
  'episodes-box': null,
  'playerContainer': null,
  'other': null
};
let containerHistory = [];

// Función auxiliar para identificar el ID del contenedor actual
function getContainerId(el) {
  if (el.closest('#content-container')) return 'content-container';
  if (el.closest('#episodes-box')) return 'episodes-box';
  if (el.closest('#playerContainer')) return 'playerContainer';
  return 'other';
}

// VALIDADOR DE VISIBILIDAD REAL EN COMPORTAMIENTO TV
function isElementRealVisible(el) {
  const style = window.getComputedStyle(el);
  const rect = el.getBoundingClientRect();
  
  return !el.disabled && 
         el.offsetParent !== null && 
         style.display !== 'none' && 
         style.visibility !== 'hidden' &&
         style.opacity !== '0' &&
         rect.width > 0 && 
         rect.height > 0;
}

function customScrollIntoView(el) {
  let container;
  let card;

  const containerId = getContainerId(el);

  // Guardar en el historial que este elemento es el último enfocado de su contenedor
  lastFocusedElements[containerId] = el;
  
  // Registrar el contenedor en el historial de navegación si cambió
  if (containerHistory[containerHistory.length - 1] !== containerId) {
    containerHistory.push(containerId);
    if (containerHistory.length > 10) containerHistory.shift();
  }

  if (containerId === 'content-container') {
    container = document.getElementById('content-container');
    card = el.closest('.card') || el;
  } else if (containerId === 'episodes-box') {
    container = document.getElementById('episodes-box');
    card = el.closest('.ep-card') || el;
  } else {
    container = el.closest('#playerContainer') || el.parentElement; 
    card = el;
  }

  if (!container || !card) return;

  const containerRect = container.getBoundingClientRect();
  const cardRect = card.getBoundingClientRect();

  const scrollTargetX = container.scrollLeft + (cardRect.left - containerRect.left) - 10;
  const scrollTargetY = container.scrollTop + (cardRect.top - containerRect.top) - 10;

  container.scrollTo({
    left: scrollTargetX,
    top: scrollTargetY,
    behavior: 'smooth'
  });
}

// OBTENER ELEMENTOS INTERACTIVOS VALIDOS Y VISIBLES
function getFocusables() {
  const selectors = 'button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
  return Array.from(document.querySelectorAll(selectors)).filter(isElementRealVisible);
}

// --- ENFOQUE SECUENCIAL ESTILO TAB (Izquierda y Derecha) ---
function focusSequentially(direction) {
  const focusables = getFocusables();
  if (focusables.length === 0) return;

  let active = document.activeElement;
  let currentIndex = focusables.indexOf(active);

  if (currentIndex === -1) {
    const target = direction === 'next' ? focusables[0] : focusables[focusables.length - 1];
    target.focus();
    customScrollIntoView(target);
    return;
  }

  let targetIndex;
  if (direction === 'next') {
    targetIndex = (currentIndex + 1) % focusables.length;
  } else {
    targetIndex = (currentIndex - 1 + focusables.length) % focusables.length;
  }

  const targetElement = focusables[targetIndex];
  if (targetElement) {
    targetElement.focus();
    customScrollIntoView(targetElement);
  }
}

// --- ENFOQUE GEOMÉTRICO (Arriba y Abajo) ---
function focusVerticalElement(direction) {
  const focusables = getFocusables();
  if (focusables.length === 0) return;

  let active = document.activeElement;
  let activeRect;

  if (!active || active === document.body || focusables.indexOf(active) === -1) {
    activeRect = {
      left: 0,
      right: window.innerWidth,
      top: direction === 'down' ? 0 : window.innerHeight,
      bottom: direction === 'down' ? 0 : window.innerHeight,
      width: 0,
      height: 0
    };
  } else {
    activeRect = active.getBoundingClientRect();
  }

  const activeCenterX = activeRect.left + activeRect.width / 2;

  let bestMatch = null;
  let minDistance = Infinity;

  focusables.forEach(el => {
    if (el === active) return;
    const rect = el.getBoundingClientRect();
    const elCenterX = rect.left + rect.width / 2;

    let isCorrectDirection = false;
    if (!active || active === document.body) {
      isCorrectDirection = true;
    } else {
      if (direction === 'down')  isCorrectDirection = rect.top >= activeRect.bottom - 5;
      if (direction === 'up')    isCorrectDirection = rect.bottom <= activeRect.top + 5;
    }

    if (isCorrectDirection) {
      const distY = Math.abs(rect.top - activeRect.top);
      const distX = Math.abs(elCenterX - activeCenterX);
      
      const totalDistance = distX + (distY * 2);

      if (totalDistance < minDistance) {
        minDistance = totalDistance;
        bestMatch = el;
      }
    }
  });

  if (bestMatch) {
    bestMatch.focus();
    customScrollIntoView(bestMatch);
  }
}

// --- REGRESAR AL CONTENEDOR ANTERIOR (Priorizando elementos .active visibles) ---
function handleBackButton() {
  const currentActive = document.activeElement;
  if (!currentActive) return false;

  const currentContainerId = getContainerId(currentActive);

  while (containerHistory.length > 0) {
    const previousContainerId = containerHistory.pop();
    
    if (previousContainerId && previousContainerId !== currentContainerId) {
      let targetElement = null;
      const containerDOM = document.getElementById(previousContainerId);

      // 1. Prioridad: Buscar un elemento con clase '.active' dentro de ese contenedor que sea REALMENTE visible
      if (containerDOM) {
        const activeElements = Array.from(containerDOM.querySelectorAll('.active'));
        targetElement = activeElements.find(el => isElementRealVisible(el) && el.matches('button, a, input, select, textarea, [tabindex]'));
      }

      // 2. Segunda opción: Si no hay .active visible, usar el último elemento que guardó la memoria por historial directo
      if (!targetElement) {
        const savedEl = lastFocusedElements[previousContainerId];
        if (savedEl && isElementRealVisible(savedEl)) {
          targetElement = savedEl;
        }
      }

      // 3. Tercera opción: Si todo falla, tomar el primer elemento focuseable de ese contenedor
      if (!targetElement && containerDOM) {
        const focusablesInContainer = Array.from(containerDOM.querySelectorAll('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])'));
        targetElement = focusablesInContainer.find(isElementRealVisible);
      }

      // Si encontramos un objetivo válido, le damos el foco
      if (targetElement) {
        targetElement.focus();
        customScrollIntoView(targetElement);
        return true; 
      }
    }
  }
  return false; 
}

// ESCUCHADOR KEYDOWN ÚNICO Y GLOBAL
document.addEventListener('keydown', (e) => {
  
  if (typeof playerContainer !== 'undefined' && playerContainer.style.display === 'block') {
    if (typeof resetInactivityTimer === 'function') {
      resetInactivityTimer();
    }
  }

  if (document.activeElement && (document.activeElement.id === 'progressContainer' || document.activeElement.classList.contains('progress-container'))) {
     if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Enter') {
        return; 
     }
  }

// --- CAPTURA MEJORADA DEL BOTÓN ATRÁS (PC, Android TV, Tizen, WebOS) ---
  const isBackKey = 
    e.key === 'Backspace' || 
    e.key === 'Escape' || 
    e.key === 'GoBack' || 
    e.key === 'BrowserBack' ||
    e.keyCode === 461 || // WebOS (LG) Back key
    e.keyCode === 10009; // Tizen (Samsung) Return/Back key

  if (isBackKey) {
    // 🌟 NUEVA EXCEPCIÓN: Si está escribiendo en un input o textarea, permitir borrar texto
    const activeEl = document.activeElement;
    if (e.key === 'Backspace' && activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.isContentEditable)) {
      return; // No hace nada y deja que el navegador borre la letra
    }

    // IMPORTANTE: Detener el comportamiento nativo de la TV de inmediato si no es un input
    e.preventDefault();
    e.stopPropagation();

    // Si tu lógica maneja el foco con éxito, salimos del evento
    if (handleBackButton()) {
      return;
    }
  }
  // --- CONTROLES DE DIRECCIÓN ---
  if (e.key === 'ArrowRight') {
    e.preventDefault();
    focusSequentially('next'); 
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault();
    focusSequentially('prev'); 
  } else if (e.key === 'ArrowDown') {
    e.preventDefault();
    focusVerticalElement('down'); 
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    focusVerticalElement('up'); 
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (document.activeElement) document.activeElement.click();
  }
});