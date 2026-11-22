import { LogOut, Menu, Moon, Search, Sun } from "lucide-react";
import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";
import { useTheme } from "@/lib/theme";

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toLocaleUpperCase("tr-TR"))
    .join("");
}

export function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
  const { theme, toggle } = useTheme();
  const { me, can, logout } = useAuth();
  const navigate = useNavigate();
  const [q, setQ] = useState("");
  const isDark = theme === "dark";

  function search(e: FormEvent) {
    e.preventDefault();
    const term = q.trim();
    if (term.length >= 2) navigate(`/lisanslar?q=${encodeURIComponent(term)}`);
  }

  async function handleLogout() {
    await logout();
    navigate("/giris", { replace: true });
  }

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-card/90 px-4 backdrop-blur md:px-6">
      <Button variant="ghost" size="icon" onClick={onMenuClick} aria-label="Menüyü aç/kapat">
        <Menu className="size-5" />
      </Button>
      <span className="hidden text-sm font-semibold lg:inline">Lisans Sunucusu</span>

      {can("license.view") && (
        <form onSubmit={search} className="ml-auto hidden w-full max-w-sm md:block" role="search">
          <label className="flex h-9 items-center gap-2 rounded-lg border bg-muted/60 px-3 text-sm focus-within:border-primary">
            <Search className="size-4 shrink-0 text-muted-foreground" />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Firma adı veya lisans kodu ara…"
              className="w-full bg-transparent outline-none placeholder:text-muted-foreground"
              aria-label="Lisans ara"
            />
          </label>
        </form>
      )}

      <div className="ml-auto flex items-center gap-1 md:ml-2">
        <button
          type="button"
          role="switch"
          aria-checked={isDark}
          aria-label={isDark ? "Açık temaya geç" : "Koyu temaya geç"}
          onClick={toggle}
          className="relative flex h-8 w-[60px] items-center rounded-full border bg-muted px-1"
        >
          <Sun className="absolute left-2 size-3.5 text-muted-foreground" />
          <Moon className="absolute right-2 size-3.5 text-muted-foreground" />
          <span
            className={`relative z-10 grid size-6 place-items-center rounded-full bg-primary text-primary-foreground shadow transition-transform duration-200 ${isDark ? "translate-x-[26px]" : ""}`}
          >
            {isDark ? <Moon className="size-3.5" /> : <Sun className="size-3.5" />}
          </span>
        </button>

        {me && (
          <div className="ml-1 flex items-center gap-2 py-1 pl-1">
            <div className="grid size-8 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
              {initials(me.name)}
            </div>
            <div className="hidden leading-tight sm:block">
              <div className="text-sm font-medium">{me.name}</div>
              <div className="text-xs text-muted-foreground">{me.role === "admin" ? "Admin" : "Kullanıcı"}</div>
            </div>
          </div>
        )}
        <Button variant="ghost" size="icon" onClick={handleLogout} aria-label="Çıkış yap" title="Çıkış yap">
          <LogOut className="size-[18px]" />
        </Button>
      </div>
    </header>
  );
}
