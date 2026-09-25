import "./app.scss";
import "@fortawesome/fontawesome-free/css/fontawesome.min.css";
import "@fortawesome/fontawesome-free/css/brands.min.css";
import "@fortawesome/fontawesome-free/css/solid.min.css";
import {initNotifications} from "./typescript/src/notifications/NotificationBell";

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

