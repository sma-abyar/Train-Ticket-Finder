(function (root) {
  'use strict';

  const transform = (x, y, scale = 1) => `translate3d(${x}px,${y}px,0) scaleX(${scale})`;
  function indicatorFrames(from, to) {
    const distance = to.x - from.x, stretch = Math.min(.14, Math.abs(distance) / Math.max(to.width, 1) * .07);
    return [
      { transform:transform(from.x, from.y, from.width / to.width), offset:0 },
      { transform:transform(from.x + distance * .72, to.y, 1 + stretch), offset:.5 },
      { transform:transform(to.x + Math.sign(distance) * 1.5, to.y, .985), offset:.8 },
      { transform:transform(to.x, to.y), offset:1 },
    ];
  }

  function createMotion(win, doc) {
    const reduced = win.matchMedia('(prefers-reduced-motion: reduce)'), running = new Set(), owned = new WeakMap();
    const specs = [
      ['quota-choices', 'input:checked', true], ['quick-dates', '.chosen'],
      ['train-filters', '[aria-pressed="true"]'], ['bottom-navigation', '[aria-current="page"]'],
      ['trip-steps', '[aria-current="step"]'],
    ];
    const groups = specs.map(([id, selector, label]) => {
      const group = doc.getElementById(id);
      if (!group) return null;
      const marker = doc.createElement(group.tagName === 'OL' ? 'li' : 'span');
      marker.className = 'segment-indicator'; marker.setAttribute('aria-hidden', 'true'); marker.hidden = true;
      group.append(marker);
      return { group, marker, selector, label, ready:false, box:null };
    }).filter(Boolean);
    let queued = false, animateNext = false;

    function cancel(element) { owned.get(element)?.cancel(); owned.delete(element); }
    function play(element, frames, duration) {
      cancel(element);
      if (reduced.matches || !element?.animate) return;
      const animation = element.animate(frames, { duration, easing:'cubic-bezier(.22,.75,.25,1)' });
      owned.set(element, animation); running.add(animation);
      const done = () => { running.delete(animation); if (owned.get(element) === animation) owned.delete(element); };
      animation.onfinish = done; animation.oncancel = done;
    }
    function update(state, animate) {
      const { group, marker } = state, selected = group.querySelector(state.selector);
      const target = state.label ? selected?.closest('label') : selected;
      const outer = group.getBoundingClientRect(), rect = target?.getBoundingClientRect();
      if (!rect || !outer.width || !rect.width || !rect.height) {
        cancel(marker); marker.hidden = true; group.dataset.motionReady = 'false'; state.ready = false; state.box = null; return;
      }
      // Physical coordinates work in RTL too, including unequal option widths.
      const box = { x:rect.left - outer.left - group.clientLeft + group.scrollLeft,
        y:rect.top - outer.top - group.clientTop + group.scrollTop, width:rect.width, height:rect.height };
      if (state.ready && state.box && Object.keys(box).every(key => Math.abs(box[key] - state.box[key]) < .5)) return;
      const previous = marker.getBoundingClientRect();
      const from = { x:previous.left - outer.left - group.clientLeft + group.scrollLeft,
        y:previous.top - outer.top - group.clientTop + group.scrollTop, width:previous.width };
      cancel(marker);
      marker.style.width = box.width + 'px'; marker.style.height = box.height + 'px';
      marker.style.transform = transform(box.x, box.y); marker.hidden = false; group.dataset.motionReady = 'true';
      if (animate && state.ready && from.width > 0) play(marker, indicatorFrames(from, box), 340);
      state.ready = true; state.box = box;
    }
    function sync(animate = true) {
      animateNext = animateNext || animate;
      if (queued) return;
      queued = true;
      win.requestAnimationFrame(() => {
        const moving = animateNext; queued = false; animateNext = false;
        groups.forEach(state => update(state, moving));
      });
    }
    function enter(element, backwards = false) {
      if (!element) return;
      play(element, [{ opacity:.6, transform:`translateX(${backwards ? 7 : -7}px)` }, { opacity:1, transform:'translateX(0)' }], 170);
    }
    function pulse(element) {
      if (element) play(element, [{ transform:'scale(.9)', opacity:.7 }, { transform:'scale(1)', opacity:1 }], 180);
    }
    function turn(element) {
      if (element) play(element, [{ transform:'rotate(0deg)' }, { transform:'rotate(180deg)' }], 240);
    }
    groups.forEach(({ group }) => {
      group.addEventListener('change', () => sync());
      group.addEventListener('click', () => sync());
    });
    if (win.ResizeObserver) {
      const observer = new win.ResizeObserver(() => sync(false));
      groups.forEach(({ group }) => observer.observe(group));
    } else win.addEventListener('resize', () => sync(false));
    doc.fonts?.ready.then(() => sync(false));
    reduced.addEventListener?.('change', () => { running.forEach(animation => animation.cancel()); running.clear(); sync(false); });
    sync(false);
    return { sync, enter, pulse, turn };
  }

  if (typeof module !== 'undefined' && module.exports) module.exports = { createMotion, indicatorFrames };
  else root.TrainMotion = createMotion(root, root.document);
})(typeof window === 'undefined' ? globalThis : window);
