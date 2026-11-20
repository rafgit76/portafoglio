(() => {
    const root = document.querySelector('.andamento-extremes');
    if (!root) return;
    const details = Array.from(root.querySelectorAll('.andamento-extreme-detail'));
    root.classList.add('andamento-extremes-enhanced');

    const position = (detail) => {
        if (!detail || !detail.open) return;
        const trigger = detail.querySelector(':scope > summary');
        const popover = detail.querySelector(':scope > .andamento-extreme-popover');
        if (!trigger || !popover) return;
        const anchor = trigger.getBoundingClientRect();
        const box = popover.getBoundingClientRect();
        const left = Math.max(8, Math.min(anchor.left, window.innerWidth - box.width - 8));
        const below = anchor.bottom + 8;
        const top = below + box.height <= window.innerHeight - 8 ? below : Math.max(8, anchor.top - box.height - 8);
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
    };

    details.forEach((detail) => {
        detail.addEventListener('toggle', () => {
            if (!detail.open) return;
            details.forEach((other) => { if (other !== detail) other.open = false; });
            position(detail);
        });
        detail.querySelector('.andamento-extreme-close')?.addEventListener('click', () => {
            detail.open = false;
            detail.querySelector(':scope > summary')?.focus();
        });
        detail.querySelector('.andamento-extreme-dates')?.addEventListener('toggle', () => position(detail));
    });
    document.addEventListener('click', (event) => {
        details.forEach((detail) => { if (detail.open && !detail.contains(event.target)) detail.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const open = details.find((detail) => detail.open);
        if (open) { open.open = false; open.querySelector(':scope > summary')?.focus(); }
    });
    window.addEventListener('resize', () => position(details.find((detail) => detail.open)));
    window.addEventListener('scroll', () => position(details.find((detail) => detail.open)), true);
})();
