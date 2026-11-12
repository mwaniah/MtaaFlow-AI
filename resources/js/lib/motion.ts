import type { ObjectDirective } from "vue";

const observers = new WeakMap<HTMLElement, IntersectionObserver>();
const focusHandlers = new WeakMap<HTMLElement, () => void>();

export const reveal: ObjectDirective<HTMLElement, number | undefined> = {
    mounted(element, binding) {
        if (
            window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
            !("IntersectionObserver" in window)
        )
            return;
        const show = () => {
            element.dataset.mfReveal = "visible";
            observers.get(element)?.disconnect();
        };
        try {
            const observer = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) show();
                },
                { threshold: 0.08 },
            );
            element.dataset.mfReveal = "pending";
            element.style.transitionDelay = `${Math.min(220, Math.max(0, binding.value ?? 0))}ms`;
            observers.set(element, observer);
            focusHandlers.set(element, show);
            element.addEventListener("focusin", show);
            observer.observe(element);
        } catch {
            show();
        }
    },
    unmounted(element) {
        observers.get(element)?.disconnect();
        const handler = focusHandlers.get(element);
        if (handler) element.removeEventListener("focusin", handler);
        observers.delete(element);
        focusHandlers.delete(element);
    },
    getSSRProps: () => ({}),
};
