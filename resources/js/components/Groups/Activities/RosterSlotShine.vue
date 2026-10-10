<template>
    <div aria-hidden="true" class="roster-slot-shine pointer-events-none absolute inset-0 z-10 overflow-hidden" />
</template>

<style scoped>
.roster-slot-shine::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(110deg, transparent 30%, rgb(255 255 255 / 18%) 50%, transparent 70%);
    transform: translateX(-100%);
    animation: roster-slot-shine 4s ease-in-out infinite;
}

@keyframes roster-slot-shine {
    0% {
        transform: translateX(-100%);
    }

    25%,
    100% {
        transform: translateX(100%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .roster-slot-shine {
        display: none;
    }

    .roster-slot-shine::before {
        animation: none;
    }
}
</style>
