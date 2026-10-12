/**
 * Collapsible side menu (design system `.menu--collapsible`, used by the admin menu): the
 * `[data-menu-toggle]` button flips `.is-open` on its `.menu` and `aria-expanded`. From the `lg`
 * breakpoint up the CSS hides the button and always shows the list.
 */
export function initializeMenuToggles(): void {
  document.querySelectorAll<HTMLButtonElement>('[data-menu-toggle]').forEach((button) => {
    const menu = button.closest('.menu');
    if (!menu) {
      return;
    }

    button.addEventListener('click', () => {
      const open = !menu.classList.contains('is-open');
      menu.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', String(open));
    });
  });
}
