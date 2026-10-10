import {t} from '../i18n';

const ACTIVE_CLASS = 'is-game-fullscreen';

/**
 * Whole-page "focus" mode for the game view: real browser fullscreen where the
 * Fullscreen API exists, plus the `is-game-fullscreen` class on <html> that the
 * stylesheet uses to hide the site chrome and enlarge the board.
 *
 * The class is the source of truth, not the API: iPhone Safari has no
 * Fullscreen API for arbitrary elements, so there the button still hides the
 * chrome (the browser's own bars stay). When the user leaves real fullscreen
 * with Esc / a system gesture, `fullscreenchange` drops the class again.
 */
export class PageFullscreen {
    private readonly root = document.documentElement;

    constructor(private readonly button: HTMLButtonElement) {
        this.button.addEventListener('click', () => void this.toggle());
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                this.setActive(false);
            }
        });
    }

    private async toggle(): Promise<void> {
        if (this.root.classList.contains(ACTIVE_CLASS)) {
            this.setActive(false);
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            }
            return;
        }

        this.setActive(true);
        // On narrow screens the button sits below the board: land on the board, not on the scrolled-off remainder of the page.
        window.scrollTo(0, 0);
        if (this.root.requestFullscreen) {
            try {
                await this.root.requestFullscreen();
            } catch {
                // Refused (permissions policy, no user activation...): the chrome-less layout still applies.
            }
        }
    }

    private setActive(active: boolean): void {
        this.root.classList.toggle(ACTIVE_CLASS, active);
        const label = active ? t('play.fullscreen.exit') : t('play.fullscreen.enter');
        this.button.title = label;
        this.button.setAttribute('aria-label', label);
        this.button.querySelector('i')?.classList.replace(active ? 'fa-expand' : 'fa-compress', active ? 'fa-compress' : 'fa-expand');
    }
}
