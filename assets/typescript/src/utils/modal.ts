/**
 * Standardized confirm/alert modal, replacing native `window.confirm()` /
 * `window.alert()` everywhere in the app. Operates on the single shared
 * `#app-confirm-modal` markup rendered site-wide in `base.html.twig`, so
 * every page (game view, lobby, friends, profile) gets the same look
 * without duplicating modal markup per page.
 */

export interface ConfirmOptions {
    title?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Renders the confirm button as `is-danger` (destructive actions like resign). */
    danger?: boolean;
}

interface ModalElements {
    modal: HTMLElement;
    title: HTMLElement;
    body: HTMLElement;
    okBtn: HTMLButtonElement;
    cancelBtn: HTMLButtonElement;
}

function getModalElements(): ModalElements | null {
    const modal = document.getElementById('app-confirm-modal');
    const title = document.getElementById('app-confirm-modal-title');
    const body = document.getElementById('app-confirm-modal-body');
    const okBtn = document.getElementById('app-confirm-modal-ok');
    const cancelBtn = document.getElementById('app-confirm-modal-cancel');

    if (!modal || !title || !body || !(okBtn instanceof HTMLButtonElement) || !(cancelBtn instanceof HTMLButtonElement)) {
        return null;
    }

    return {modal, title, body, okBtn, cancelBtn};
}

function openModal(message: string, title: string, okLabel: string, okDanger: boolean, showCancel: boolean, cancelLabel: string): Promise<boolean> {
    const els = getModalElements();

    if (!els) {
        console.error('Standardized modal markup (#app-confirm-modal) is missing from this page.');

        return Promise.resolve(false);
    }

    const {modal, title: titleEl, body, okBtn, cancelBtn} = els;

    return new Promise((resolve) => {
        titleEl.textContent = title;
        body.textContent = message;
        okBtn.textContent = okLabel;
        okBtn.className = `button is-rounded ${okDanger ? 'is-danger' : 'is-primary'}`;
        cancelBtn.textContent = cancelLabel;
        cancelBtn.style.display = showCancel ? '' : 'none';

        const controller = new AbortController();
        const close = (result: boolean): void => {
            controller.abort();
            modal.classList.remove('is-active');
            resolve(result);
        };

        okBtn.addEventListener('click', () => close(true), {signal: controller.signal});
        cancelBtn.addEventListener('click', () => close(false), {signal: controller.signal});
        modal.querySelector('.modal-background')?.addEventListener('click', () => close(false), {signal: controller.signal});
        document.addEventListener('keydown', (event) => {
            if ('Escape' === event.key) close(false);
        }, {signal: controller.signal});

        modal.classList.add('is-active');
        okBtn.focus();
    });
}

/** Replaces `window.confirm()`. Resolves `true` when the user confirms. */
export function confirmModal(message: string, options: ConfirmOptions = {}): Promise<boolean> {
    return openModal(
        message,
        options.title ?? 'Confirm',
        options.confirmLabel ?? 'Confirm',
        options.danger ?? false,
        true,
        options.cancelLabel ?? 'Cancel',
    );
}

/** Replaces `window.alert()`. Resolves once the user dismisses it. */
export async function alertModal(message: string, title = 'Notice'): Promise<void> {
    await openModal(message, title, 'OK', false, false, 'Cancel');
}
