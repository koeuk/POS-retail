import { useMediaQuery } from '@vueuse/core';

/**
 * The one breakpoint the shell switches on. 768px is Tailwind's `md`, so the
 * JS boundary and every `md:` class in the templates agree — a layout that
 * disagreed with its own media queries would flicker at the seam.
 */
export const MOBILE_BREAKPOINT = 768;

export function useIsMobile() {
    return useMediaQuery(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
}
