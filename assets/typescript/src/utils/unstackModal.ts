/**
 * The "stack or unstack" / "stack or select" dialog of a move onto a square that is ambiguous. Operates on the
 * `#unstack-modal` markup of `actions/_unstack_modal.html.twig`, shared by the game page and the tutorial so that both
 * ask the very same question the very same way. Which buttons are offered is decided by the caller (the game takes
 * it from the engine's move flags, the tutorial from the authored puzzle); this only shows them and reports the answer.
 */
import {t} from '../i18n';

/** Which buttons to offer. `selectInstead` also switches the wording to the "you clicked your own piece" variant. */
export interface UnstackModalDetail {
    full: boolean;
    top: boolean;
    selectInstead: boolean;
}

/** `stack` = the whole stack / stack onto the piece, `unstack` = only the top piece, `select` = select that piece instead, `null` = dismissed. */
export type UnstackChoice = 'stack' | 'unstack' | 'select' | null;

function el<T extends HTMLElement>(id: string): T | null {
    return document.getElementById(id) as T | null;
}

export function askUnstack(detail: UnstackModalDetail): Promise<UnstackChoice> {
    const modal = el('unstack-modal');
    const title = el('unstack-modal-title');
    const text = el('unstack-modal-text');
    const stackBtn = el<HTMLButtonElement>('move-stack');
    const unstackBtn = el<HTMLButtonElement>('move-unstack');
    const selectBtn = el<HTMLButtonElement>('select-instead');

    if (!modal || !title || !text || !stackBtn || !unstackBtn || !selectBtn) {
        console.error('Unstack modal markup (#unstack-modal) is missing from this page.');

        return Promise.resolve(null);
    }

    const {full, top, selectInstead} = detail;

    if (selectInstead) {
        title.textContent = t('play.stack_modal.select_title');
        text.textContent = full && top
            ? t('play.stack_modal.select_text_full_top')
            : (top ? t('play.stack_modal.select_text_top') : t('play.stack_modal.select_text_stack'));
        stackBtn.textContent = top ? t('play.stack_modal.move_full_stack') : t('play.stack_modal.stack_here');
    } else {
        title.textContent = t('play.stack_modal.choose_title');
        text.textContent = t('play.stack_modal.choose_text');
        stackBtn.textContent = t('play.stack_modal.move_full_stack');
    }
    stackBtn.hidden = !full;
    unstackBtn.hidden = !top;
    selectBtn.hidden = !selectInstead;
    stackBtn.classList.toggle('btn--primary', full);
    unstackBtn.classList.toggle('btn--primary', !full && top);

    return new Promise((resolve) => {
        const controller = new AbortController();
        const {signal} = controller;
        const close = (choice: UnstackChoice): void => {
            controller.abort();
            modal.classList.remove('is-active');
            resolve(choice);
        };

        stackBtn.addEventListener('click', () => close('stack'), {signal});
        unstackBtn.addEventListener('click', () => close('unstack'), {signal});
        selectBtn.addEventListener('click', () => close('select'), {signal});
        modal.querySelector('.modal__backdrop')?.addEventListener('click', () => close(null), {signal});
        // Capture phase: Escape belongs to the dialog while it is open (the page must not also close its menus).
        document.addEventListener('keydown', (event) => {
            if ('Escape' === event.key) {
                event.stopImmediatePropagation();
                close(null);
            }
        }, {signal, capture: true});

        modal.classList.add('is-active');
        (full ? stackBtn : unstackBtn).focus();
    });
}
