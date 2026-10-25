(() => {
    'use strict';
    const video = document.querySelector('.result-video');
    if (!video) return;
    // Poster is also the final still; playback failure leaves it visible.
    video.addEventListener('ended', () => { video.load(); }, {once: true});
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        video.muted = true;
        const playback = video.play();
        if (playback) playback.catch(() => {});
    }
})();
