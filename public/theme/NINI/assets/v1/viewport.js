(function () {
  'use strict';
  const page = document.documentElement;
  const canvasWidth = 1536;
  let pending = 0, lastWidth = 0, lastHeight = 0, lastDesktop = null;
  function fitCanvas() {
    pending = 0;
    const width = page.clientWidth || window.innerWidth;
    const height = window.innerHeight;
    const desktop = window.matchMedia('(min-width:1025px)').matches;
    if (!width || !height || (width === lastWidth && height === lastHeight && desktop === lastDesktop)) return;
    lastWidth = width;
    lastHeight = height;
    lastDesktop = desktop;
    const scale = desktop ? width / canvasWidth : 1;
    page.style.setProperty('--nini-viewport-scale', String(scale));
    page.style.setProperty('--nini-canvas-viewport-height', (height / scale) + 'px');
    page.classList.toggle('nini-fit-viewport', desktop);
    page.classList.toggle('nini-mobile-viewport', !desktop);
  }
  window.addEventListener('resize', function () {
    if (!pending) pending = window.requestAnimationFrame(fitCanvas);
  }, {passive:true});
  fitCanvas();
})();
