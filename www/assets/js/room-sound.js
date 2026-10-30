/* UI notifications only; no match timer, commands or separate preference storage. */
(() => {
    'use strict';
    let context, pending = null, navigating = false, generation = 0;
    const seen = new Set(), tones = new Set();
    const enabled = () => window.BerserkPreferences?.current?.sound_enabled === true;
    try {
        JSON.parse(sessionStorage.getItem('berserk-room-sounds') || '[]').forEach(key => seen.add(key));
        pending = JSON.parse(sessionStorage.getItem('berserk-room-sound-pending'));
    } catch (_) { /* Notifications cannot interfere with navigation. */ }
    function arm() {
        if (!enabled()) return;
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            if (!context && Audio) context = new Audio();
            if (context?.state === 'suspended') context.resume().catch(() => {});
        } catch (_) { /* Browser autoplay policy is authoritative. */ }
    }
    function consume() {
        if (navigating || !pending || !window.BerserkPreferences?.current) return;
        const event = pending; pending = null;
        try { sessionStorage.removeItem('berserk-room-sound-pending'); } catch (_) {}
        if (!enabled() || Date.now() - event.at > 5000) return;
        arm();
        const expectedGeneration = generation;
        const play = () => {
            if (generation !== expectedGeneration || !enabled() || context?.state !== 'running' || Date.now() - event.at > 5000) return;
            [660, 880].forEach((frequency, index) => {
                const oscillator = context.createOscillator(), gain = context.createGain();
                const at = context.currentTime + index * 0.14;
                oscillator.type = 'sine'; oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0, at); gain.gain.linearRampToValueAtTime(0.07, at + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, at + 0.12);
                oscillator.connect(gain); gain.connect(context.destination);
                tones.add(oscillator); oscillator.start(at); oscillator.stop(at + 0.13);
                oscillator.onended = () => { tones.delete(oscillator); oscillator.disconnect(); gain.disconnect(); };
            });
        };
        try { if (context?.state === 'suspended') context.resume().then(play).catch(() => {}); else play(); } catch (_) {}
    }
    window.BerserkRoomSound = {
        notify(key, afterNavigation = false) {
            if (seen.has(key)) return;
            seen.add(key);
            pending = {at: Date.now()}; navigating = afterNavigation;
            try {
                sessionStorage.setItem('berserk-room-sounds', JSON.stringify([...seen].slice(-32)));
                sessionStorage.setItem('berserk-room-sound-pending', JSON.stringify(pending));
            } catch (_) {}
            if (!afterNavigation) consume();
        }
    };
    document.addEventListener('pointerdown', arm);
    document.addEventListener('keydown', arm);
    document.addEventListener('berserk:preferences', () => {
        if (!enabled()) { generation++; tones.forEach(tone => { try { tone.stop(); } catch (_) {} }); }
        consume();
    });
    consume();
})();
