
(function (global) {
  'use strict';

  var tasks = {};
  var running = false;
  var suppressed = false;
  var idle = false;

  var IDLE_AFTER_MS = 90000;
  var ACTIVE_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'focus'];
  var idleTimer = null;

  function register(name, fn, opts) {
    if (!name || typeof fn !== 'function') return;
    opts = opts || {};
    var fast = clampInterval(opts.fast, 15000);
    var slow = clampInterval(opts.slow, Math.max(fast, 60000));

    tasks[name] = {
      fn: fn,
      fast: fast,
      slow: slow,
      timer: null,
      running: false,
      lastRun: 0
    };
  }

  function clampInterval(value, fallback) {
    var n = Number(value);
    if (!isFinite(n) || n < 1000) return fallback;
    return n;
  }

  function currentInterval(task) {
    return (idle || suppressed) ? task.slow : task.fast;
  }

  function scheduleTask(name) {
    var task = tasks[name];
    if (!task || !running) return;

    clearTimeout(task.timer);
    var delay = currentInterval(task);
    task.timer = setTimeout(function () { runTask(name); }, delay);
  }

  function rescheduleAll(immediate) {
    Object.keys(tasks).forEach(function (name, idx) {
      if (!running) return;
      var task = tasks[name];
      clearTimeout(task.timer);
      if (immediate) {
        var stagger = Math.min(idx * 120, 1200);
        task.timer = setTimeout(function () { runTask(name); }, stagger);
      } else {
        scheduleTask(name);
      }
    });
  }

  function runTask(name) {
    var task = tasks[name];
    if (!task || !running) return;

    if (suppressed) { scheduleTask(name); return; }

    if (task.running) { scheduleTask(name); return; }

    task.running = true;
    task.lastRun = Date.now();

    var result;
    try {
      result = task.fn();
    } catch (e) {
      logError(name, e);
      task.running = false;
      scheduleTask(name);
      return;
    }

    if (result && typeof result.then === 'function') {
      result.then(function () {
        task.running = false;
        scheduleTask(name);
      }, function (e) {
        logError(name, e);
        task.running = false;
        scheduleTask(name);
      });
    } else {
      task.running = false;
      scheduleTask(name);
    }
  }

  function logError(name, e) {
    if (global.console && console.warn) {
      console.warn('[polling] task "' + name + '" failed:', e && e.message ? e.message : e);
    }
  }

  function start() {
    if (running) return;
    running = true;
    idle = false;
    resetIdleTimer();
    attachActivityListeners();
    document.addEventListener('visibilitychange', onVisibilityChange);

    rescheduleAll(true);
  }

  function stop() {
    running = false;
    Object.keys(tasks).forEach(function (name) {
      clearTimeout(tasks[name].timer);
      tasks[name].timer = null;
      tasks[name].running = false;
    });
    detachActivityListeners();
    document.removeEventListener('visibilitychange', onVisibilityChange);
    if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
  }

  function refresh(name) {
    var task = tasks[name];
    if (!task) return;
    clearTimeout(task.timer);
    runTask(name);
  }

  function suppress(flag) {
    var next = !!flag;
    if (next === suppressed) return;
    suppressed = next;
    if (running && !suppressed) {
      rescheduleAll(true);
    } else {
      rescheduleAll(false);
    }
  }

  function attachActivityListeners() {
    ACTIVE_EVENTS.forEach(function (evt) {
      global.addEventListener(evt, onActivity, { passive: true });
    });
  }

  function detachActivityListeners() {
    ACTIVE_EVENTS.forEach(function (evt) {
      global.removeEventListener(evt, onActivity);
    });
  }

  function onActivity() {
    if (idle) {
      idle = false;
      if (running) rescheduleAll(false);
    }
    resetIdleTimer();
  }

  function resetIdleTimer() {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
      idle = true;
      if (running) rescheduleAll(false);
    }, IDLE_AFTER_MS);
  }

  function onVisibilityChange() {
    if (document.hidden) {
      Object.keys(tasks).forEach(function (name) {
        clearTimeout(tasks[name].timer);
        tasks[name].timer = null;
      });
    } else if (running) {
      idle = false;
      resetIdleTimer();
      rescheduleAll(true);
    }
  }

  var api = {
    register: register,
    start: start,
    stop: stop,
    refresh: refresh,
    suppress: suppress,

    _tasks: function () { return Object.keys(tasks); },
    _isRunning: function () { return running; },
    _isIdle: function () { return idle; }
  };

  if (!global.CemboClear) global.CemboClear = {};
  global.CemboClear.polling = api;
})(typeof window !== 'undefined' ? window : globalThis);
