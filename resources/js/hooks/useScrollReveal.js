import { useEffect, useRef } from 'react';

export default function useScrollReveal() {
    const containerRef = useRef(null);

    useEffect(() => {
        const container = containerRef.current;
        if (!container || !window.IntersectionObserver) return;

        const elements = [...container.querySelectorAll('[data-scroll-reveal]')];
        const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
        let observer;

        const reset = () => {
            observer?.disconnect();
            elements.forEach((element) => {
                delete element.dataset.revealReady;
                delete element.dataset.revealVisible;
            });
        };

        const start = () => {
            reset();
            if (motionPreference.matches) return;

            observer = new window.IntersectionObserver((entries) => {
                entries.forEach(({ target, isIntersecting, intersectionRatio }) => {
                    if (isIntersecting && intersectionRatio >= 0.1) {
                        target.dataset.revealVisible = 'true';
                    } else if (!isIntersecting && !target.contains(document.activeElement)) {
                        // Reset only after leaving the viewport, so scrolling back
                        // into a section plays the entrance again without flicker.
                        target.dataset.revealVisible = 'false';
                    }
                });
            }, { threshold: [0, 0.1] });

            elements.forEach((element) => {
                element.dataset.revealReady = 'true';
                element.dataset.revealVisible = 'false';
                observer.observe(element);
            });
        };

        const revealFocusedElement = (event) => {
            const element = event.target.closest('[data-scroll-reveal]');
            if (element && container.contains(element)) {
                element.dataset.revealVisible = 'true';
            }
        };

        start();
        motionPreference.addEventListener('change', start);
        container.addEventListener('focusin', revealFocusedElement);

        return () => {
            reset();
            motionPreference.removeEventListener('change', start);
            container.removeEventListener('focusin', revealFocusedElement);
        };
    }, []);

    return containerRef;
}
