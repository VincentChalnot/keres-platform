/**
 * "Do you play chess?" (`templates/actions/_experience_modal.html.twig`). Two ways in:
 * - on the home page, a click on a bot-game form marked `[data-experience-gate]` opens the modal instead of starting the
 *   game; the answer is stored (`POST /onboarding/experience`) and then "player"/"beginner" lead to the tutorial while
 *   "skip" lets the original form go through;
 * - on /tutorial the modal is open from the start (`.is-active`): the answer is stored and the page stays (skip leaves
 *   for the home page).
 * Each screen of the modal can be dismissed (Escape, cross): no answer, the question comes back next time.
 */
export function initExperienceModal(): void {
    const modal = document.querySelector<HTMLElement>('[data-experience-modal]');
    if (!modal) {
        return;
    }

    const {url = '', tutorialUrl = '', homeUrl = '/'} = modal.dataset;
    let pending: HTMLFormElement | null = null;
    let busy = false;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.matches('[data-experience-gate]')) {
            event.preventDefault();
            pending = form;
            modal.classList.add('is-active');
        }
    }, true);

    modal.querySelectorAll<HTMLButtonElement>('[data-experience]').forEach((button) => {
        button.addEventListener('click', () => void answer(button.dataset.experience ?? 'skipped'));
    });

    async function answer(experience: string): Promise<void> {
        if (busy) {
            return;
        }
        busy = true;

        try {
            await fetch(url, {method: 'POST', body: new URLSearchParams({experience})});
        } catch {
            // The answer is a convenience: carry on without it.
        }

        modal!.classList.remove('is-active');
        document.querySelectorAll('[data-experience-gate]').forEach((form) => form.removeAttribute('data-experience-gate'));

        if (null === pending) {
            // /tutorial itself: stay on it (the page switches to the matching track), unless the player wants to skip to the game.
            if ('skipped' === experience) {
                window.location.href = homeUrl;
            } else {
                window.dispatchEvent(new CustomEvent('keres:experience', {detail: experience}));
            }
        } else if ('skipped' !== experience && '' !== tutorialUrl) {
            window.location.href = tutorialUrl;
        } else {
            pending.submit();
        }

        busy = false;
    }
}
