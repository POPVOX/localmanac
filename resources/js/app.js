document.documentElement.classList.add('lw-nav-switch-freeze');

if (! window.__switchTransitionFreezeBound) {
    const freezeSwitchTransitions = () => {
        document.documentElement.classList.add('lw-nav-switch-freeze');
    };

    const unfreezeSwitchTransitions = () => {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                document.documentElement.classList.remove('lw-nav-switch-freeze');
            });
        });
    };

    document.addEventListener('livewire:navigate', freezeSwitchTransitions);
    document.addEventListener('livewire:navigating', freezeSwitchTransitions);
    document.addEventListener('livewire:navigated', unfreezeSwitchTransitions);

    window.__switchTransitionFreezeBound = true;
}
