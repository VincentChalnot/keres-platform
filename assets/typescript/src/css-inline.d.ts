// Vite's `?inline` query turns a stylesheet into a plain string module (the CSS text, not injected).
declare module '*.css?inline' {
    const css: string;
    export default css;
}
