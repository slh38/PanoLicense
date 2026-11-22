import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, ensureCsrfCookie, setUnauthorizedHandler } from "./api";

export interface Me {
  id: number;
  name: string;
  username: string;
  role: "admin" | "user";
  permissions: string[];
}

interface AuthState {
  me: Me | null;
  loading: boolean;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  /** Yalnız görünürlük içindir; asıl yetki kontrolü sunucudadır. */
  can: (ability: string) => boolean;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setUnauthorizedHandler(() => setMe(null));
    api<Me>("/me")
      .then(setMe)
      .catch(() => setMe(null))
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    await ensureCsrfCookie(true);
    await api("/auth/login", { method: "POST", body: { username, password } });
    setMe(await api<Me>("/me"));
  }, []);

  const logout = useCallback(async () => {
    try {
      await api("/auth/logout", { method: "POST" });
    } finally {
      setMe(null);
    }
  }, []);

  const can = useCallback((ability: string) => !!me?.permissions.includes(ability), [me]);

  return <AuthContext.Provider value={{ me, loading, login, logout, can }}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth, AuthProvider içinde kullanılmalı.");
  return ctx;
}
