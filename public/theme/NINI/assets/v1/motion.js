(function () {
  'use strict';
  const N = window.Nini;
  if (!N || N.refreshParallax) return;

  const reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : {matches:false};
  const sprites = new Map();
  const scenes = new Map();
  const travel = 24;
  let root, observedRoot;

  function enabled() {
    const mode = root && root.dataset.decorationMotion;
    return !!root && mode !== 'off' && (mode === 'on' || !reduced.matches);
  }

  function stop(sprite) {
    if (sprite.animation) sprite.animation.cancel();
    sprite.animation = null;
    sprite.element.removeAttribute('data-decoration-drifting');
  }

  function readBounds(element) {
    const bound = (key, fallback) => {
      const raw = element.dataset[key];
      const value = raw === undefined || String(raw).trim() === '' ? NaN : Number(raw);
      return Number.isFinite(value) ? Math.max(-travel, Math.min(travel, value)) : fallback;
    };
    const bounds = [bound('driftXMin', -travel), bound('driftXMax', travel), bound('driftYMin', -travel), bound('driftYMax', travel)];
    for (let axis = 0; axis < 4; axis += 2) {
      if (bounds[axis] > bounds[axis + 1]) {
        bounds[axis] = bounds[axis + 1] = (bounds[axis] + bounds[axis + 1]) / 2;
      }
    }
    return bounds;
  }

  function mapAxis(value, min, max) {
    const position = (min + max) / 2 + value / travel * (max - min) / 2;
    return Math.max(min, Math.min(max, Math.round(position * 1000) / 1000));
  }

  function trajectory(depth, bounds) {
    // Sample a smooth periodic curve once. The browser then interpolates only
    // transforms; no frame callbacks, pointer handlers or layout reads are needed.
    const tau = Math.PI * 2;
    const direction = Math.random() < .5 ? -1 : 1;
    const phaseX = Math.random() * tau;
    const phaseY = Math.random() * tau;
    const phaseTurn = Math.random() * tau;
    const amplitude = Math.min(travel, 20 + Math.abs(depth) * 3);
    const radiusX = amplitude * (.9 + Math.random() * .1);
    const radiusY = amplitude * (.8 + Math.random() * .2);
    const frames = [];
    for (let i = 0; i <= 64; i++) {
      const angle = i / 64 * tau * direction;
      const x = mapAxis(radiusX * (.8 * Math.sin(angle + phaseX) + .2 * Math.sin(2 * angle + phaseY)), bounds[0], bounds[1]);
      const y = mapAxis(radiusY * (.8 * Math.cos(angle + phaseY) + .2 * Math.sin(2 * angle + phaseX)), bounds[2], bounds[3]);
      const turn = 2.4 * Math.sin(angle + phaseTurn);
      frames.push({offset:i / 64, transform:`translate3d(${x}px, ${y}px, 0) rotate(${turn.toFixed(3)}deg)`});
    }
    // Identical endpoints ensure there is no seam when the path repeats.
    frames[64].transform = frames[0].transform;
    return frames;
  }

  function sync(sprite) {
    if (!enabled() || !sprite.element.isConnected) { stop(sprite); return; }
    if (!sprite.animation && typeof sprite.element.animate === 'function') {
      const duration = 8000 + Math.random() * 6000;
      sprite.animation = sprite.element.animate(trajectory(sprite.depth, sprite.bounds), {
        duration, iterations:Infinity, easing:'linear', fill:'none'
      });
      sprite.animation.currentTime = Math.random() * duration;
    }
    if (!sprite.animation) return;
    if (document.hidden || !sprite.scene.visible) {
      sprite.animation.pause();
      sprite.element.removeAttribute('data-decoration-drifting');
    } else {
      if (sprite.animation.playState !== 'running') sprite.animation.play();
      sprite.element.setAttribute('data-decoration-drifting', '');
    }
  }

  function syncAll() { sprites.forEach(sync); }

  const visibilityObserver = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
    entries.forEach(entry => {
      const scene = scenes.get(entry.target);
      if (!scene) return;
      scene.visible = entry.isIntersecting;
      scene.sprites.forEach(sync);
    });
  }, {threshold:0}) : null;

  const settingObserver = typeof MutationObserver === 'function' ? new MutationObserver(syncAll) : null;

  // Keep the existing hook so route rendering and settings continue to use the
  // same integration point, although the effect no longer follows the mouse.
  N.refreshParallax = function () {
    root = document.getElementById('nini-root');
    const foundSprites = new Set();
    const foundScenes = new Set();
    if (root) Array.from(root.querySelectorAll('[data-parallax-scene]')).forEach(element => {
      foundScenes.add(element);
      let scene = scenes.get(element);
      if (!scene) {
        scene = {element, visible:true, sprites:[]};
        scenes.set(element, scene);
        if (visibilityObserver) visibilityObserver.observe(element);
      }
      scene.sprites = [];
      Array.from(element.querySelectorAll('[data-parallax-depth]')).forEach(spriteElement => {
        if (spriteElement.closest('[data-parallax-scene]') !== element) return;
        const value = Number(spriteElement.dataset.parallaxDepth);
        if (value === 0) return;
        foundSprites.add(spriteElement);
        const bounds = readBounds(spriteElement);
        let sprite = sprites.get(spriteElement);
        if (!sprite) {
          sprite = {element:spriteElement, depth:Number.isFinite(value) ? value : 1, bounds, animation:null};
          sprites.set(spriteElement, sprite);
        } else if (bounds.some((bound, index) => bound !== sprite.bounds[index])) {
          stop(sprite);
          sprite.bounds = bounds;
        }
        sprite.scene = scene;
        scene.sprites.push(sprite);
      });
    });
    sprites.forEach((sprite, element) => {
      if (!foundSprites.has(element)) { stop(sprite); sprites.delete(element); }
    });
    scenes.forEach((scene, element) => {
      if (!foundScenes.has(element)) {
        if (visibilityObserver) visibilityObserver.unobserve(element);
        scenes.delete(element);
      }
    });
    if (settingObserver && root !== observedRoot) {
      settingObserver.disconnect();
      if (root) settingObserver.observe(root, {attributes:true, attributeFilter:['data-decoration-motion']});
      observedRoot = root;
    }
    syncAll();
  };

  document.addEventListener('visibilitychange', syncAll);
  if (reduced.addEventListener) reduced.addEventListener('change', syncAll);
  else if (reduced.addListener) reduced.addListener(syncAll);
  N.refreshParallax();
})();
