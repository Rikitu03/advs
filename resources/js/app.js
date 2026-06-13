// Global safety net: any Livewire request that fails at the transport level
// (session expiry, server error, lost connection) surfaces as a toast instead
// of dying silently. Page-specific feedback stays in the components themselves.
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status }) => {
            const message =
                status === 419
                    ? 'Your session has expired. Please refresh the page and try again.'
                    : 'Something went wrong while talking to the server. Please try again.';

            window.Flux?.toast({
                heading: 'Request failed',
                text: message,
                variant: 'danger',
            });
        });
    });
});
