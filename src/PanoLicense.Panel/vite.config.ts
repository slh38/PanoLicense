import path from "node:path";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

// İleride API ile aynı domain altında yayın: /api istekleri dev ortamında API'ye proxylenir.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { "@": path.resolve(__dirname, "./src") } },
  server: {
    port: 5173,
    proxy: { "/api": { target: "http://localhost:5080", changeOrigin: true } },
  },
  test: { environment: "node" },
});
