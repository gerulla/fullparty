import { ref } from "vue";

export const useRosterSlotTextExpansion = () => {
    const isTextExpanded = ref(false);

    const handleSlotTap = (event: MouseEvent) => {
        const pointerType = (event as PointerEvent).pointerType;
        const isTouch = pointerType === "touch" || pointerType === "pen"
            || (!pointerType && window.matchMedia("(hover: none)").matches);

        if (!isTouch || !(event.target instanceof Element)) {
            return;
        }

        // Let slot actions and links keep their own tap behavior.
        if (event.target.closest('a, button, input, select, textarea, [role="button"], [role="link"], [contenteditable="true"]')) {
            return;
        }

        isTextExpanded.value = !isTextExpanded.value;
    };

    return { isTextExpanded, handleSlotTap };
};
