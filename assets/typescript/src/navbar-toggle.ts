/**
 * Mobile navigation burger: toggles the vertical menu below the design system's desktop
 * breakpoint (1024px; the CSS hides the burger and always shows the menu above it).
 * `.is-active` on the burger and on `.navbar__menu` is the contract of src/components/navigation.css.
 */
export function initializeNavbarToggle(): void {
  const burger = document.getElementById('navbar-burger');
  const menu = document.getElementById('navbar-menu');
  if (!burger || !menu) {
    return;
  }

  const setOpen = (open: boolean): void => {
    burger.classList.toggle('is-active', open);
    menu.classList.toggle('is-active', open);
    burger.setAttribute('aria-expanded', String(open));
  };

  burger.addEventListener('click', () => setOpen(!menu.classList.contains('is-active')));

  // Navigating within the same page (anchors) or back/forward cache restores must not leave a stale open menu.
  menu.querySelectorAll('a.navbar__link').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });
  window.addEventListener('pageshow', () => setOpen(false));

  // The desktop account dropdown (a <details>) closes on an outside click and on Escape.
  const account = document.getElementById('navbar-account');
  if (account instanceof HTMLDetailsElement) {
    document.addEventListener('click', (event) => {
      if (event.target instanceof Node && !account.contains(event.target)) {
        account.open = false;
      }
    });
    document.addEventListener('keydown', (event) => {
      if ('Escape' === event.key) {
        account.open = false;
      }
    });
  }
}
