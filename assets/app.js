// Styles: the Keres design system (Tailwind, see docs/design-system.md) and Font Awesome.
import "./design-system.css";
import "@fortawesome/fontawesome-free/css/fontawesome.min.css";
import "@fortawesome/fontawesome-free/css/brands.min.css";
import "@fortawesome/fontawesome-free/css/solid.min.css";
import "@fortawesome/fontawesome-free/css/regular.min.css";
import {initNotifications} from "./typescript/src/notifications/NotificationBell";
import {initializeNavbarToggle} from "./typescript/src/navbar-toggle";
import {initializeMenuToggles} from "./typescript/src/menu-toggle";

// ─── Mobile navigation burger (every page) ───────────────────────────────────

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeNavbarToggle);
} else {
    initializeNavbarToggle();
}

// ─── Collapsible side menus (admin) ──────────────────────────────────────────

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeMenuToggles);
} else {
    initializeMenuToggles();
}

// ─── Notification bell (every signed-in page) ────────────────────────────────

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNotifications);
} else {
    initNotifications();
}

// ─── Lightbox ────────────────────────────────────────────────────────────────

document.addEventListener("DOMContentLoaded", () => {
    const lightbox = document.getElementById("lightbox");
    const lightboxImg = document.getElementById("lightbox-img");
    const lightboxClose = document.getElementById("lightbox-close");

    if (!lightbox || !lightboxImg || !lightboxClose) return;

    const open = (src, alt) => {
        lightboxImg.src = src;
        lightboxImg.alt = alt || "";
        lightbox.classList.add("is-active");
        document.body.style.overflow = "hidden";
    };

    const close = () => {
        lightbox.classList.remove("is-active");
        document.body.style.overflow = "";
        lightboxImg.src = "";
    };

    document.querySelectorAll("a.lightbox-trigger").forEach((trigger) => {
        trigger.addEventListener("click", (e) => {
            e.preventDefault();
            const img = trigger.querySelector("img");
            open(trigger.href, img ? img.alt : "");
        });
    });

    lightboxClose.addEventListener("click", close);

    lightbox.addEventListener("click", (e) => {
        if (e.target === lightbox) close();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && lightbox.classList.contains("is-active")) close();
    });
});


// ─── Home page, anonymous: a guest game stored in this browser (see LocalGameAPI) turns "Play now" into "Resume" ───

document.addEventListener("DOMContentLoaded", () => {
    const resume = document.querySelector("[data-guest-resume]");
    if (!resume) return;

    try {
        const saved = JSON.parse(window.localStorage.getItem("keres.guestGame.v1") || "null");
        if (!saved || !Array.isArray(saved.moves) || saved.moves.length === 0 || saved.resignedColor) return;
    } catch {
        return;
    }

    resume.hidden = false;
    const fresh = document.querySelector("[data-guest-new]");
    if (fresh) fresh.hidden = true;
});

// ─── Server-rendered modals: [data-modal-open="#id"] opens .modal#id; [data-close], the backdrop and Escape close it ───

document.addEventListener("click", (event) => {
    if (!(event.target instanceof Element)) return;

    const opener = event.target.closest("[data-modal-open]");
    if (opener) {
        document.querySelector(opener.dataset.modalOpen)?.classList.add("is-active");
        return;
    }

    if (event.target.closest("[data-close]") || event.target.classList.contains("modal__backdrop")) {
        event.target.closest(".modal[data-dismissible]")?.classList.remove("is-active");
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        document.querySelectorAll(".modal.is-active[data-dismissible]").forEach((modal) => modal.classList.remove("is-active"));
    }
});

// ─── Tutorial invitation banner (dismiss: remembered in the browser and, signed in, on the account) ───────────────

document.addEventListener("click", (event) => {
    const button = event.target instanceof Element ? event.target.closest("[data-onboarding-dismiss]") : null;
    if (!button) return;

    document.getElementById("onboarding-banner")?.remove();
    fetch("/onboarding/complete", {method: "POST"}).catch(() => {});
});
