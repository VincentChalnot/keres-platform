// Vite inlines @import before these plugins run (no postcss-import needed).
export default {
    plugins: {
        tailwindcss: {},
        autoprefixer: {},
    },
};
