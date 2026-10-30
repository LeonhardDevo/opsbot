export function printLinks(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const body = document.body;
    const cleanup = () => {
        body.classList.remove('printing-links');
        window.removeEventListener('afterprint', cleanup);
    };

    body.classList.add('printing-links');
    window.addEventListener('afterprint', cleanup);
    requestAnimationFrame(() => {
        window.print();
        setTimeout(cleanup, 1000);
    });
}
