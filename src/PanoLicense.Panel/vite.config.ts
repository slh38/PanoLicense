import path from "node:path";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// Yayında panel Laravel ile aynı domain'dedir: build çıktısı Laravel public/spa klasörüne gider
// (npm run build:deploy), dosyalar /spa/ altından sunulur, sayfa yolları Laravel fallback'iyle index.html'e düşer.
// Geliştirmede /api ve /sanctum istekleri Laravel dev sunucusuna (php artisan serve) proxylenir;
// böylece tarayıcı açısından her şey aynı origin'dir ve Sanctum cookie oturumu çalışır.
const laravel = "http://127.0.0.1:8000";

export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { "@": path.resolve(import.meta.dirname, "./src") } },
  base: mode === "deploy" ? "/spa/" : "/",
  build:
    mode === "deploy"
      ? { outDir: path.resolve(import.meta.dirname, "../PanoLicense.Api/public/spa"), emptyOutDir: true }
      : undefined,
  server: {
    port: 5173,
    proxy: {
      "/api": { target: laravel, changeOrigin: false },
      "/sanctum": { target: laravel, changeOrigin: false },
    },
  },
  test: { environment: "node" },
}));
