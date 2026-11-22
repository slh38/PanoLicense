import { Bell, Menu, Moon, Search, Sun } from "lucide-react";
import { Button } from "@/components/ui/button";
import { mockUser } from "@/data/mockDashboard";
import { useTheme } from "@/lib/theme";

export function Topbar({ onMenuClick }: { onMenuClick: () => void }) {
  const { theme, toggle } = useTheme();
  const isDark = theme === "dark";

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-card/90 px-4 backdrop-blur md:px-6">
      <Button variant="ghost" size="icon" onClick={onMenuClick} aria-label="Menüyü aç/kapat">
        <Menu className="size-5" />
      </Button>
      <span className="hidden text-sm font-semibold sm:inline">Lisans Sunucusu</span>

      {/* Arama F2'de API'ye bağlanacak; şimdilik pasif. */}
      <div
        className="ml-auto hidden h-9 w-full max-w-sm items-center gap-2 rounded-lg border bg-muted/60 px-3 text-sm text-muted-foreground md:flex"
        title="Arama, lisans API'si bağlandığında aktif olacak"
        aria-disabled="true"
      >
        <Search className="size-4" />
        <span className="truncate">Müşteri, lisans kodu veya cihaz ara...</span>
        <kbd className="ml-auto rounded border bg-card px-1.5 text-[10px] font-medium">Ctrl K</kbd>
      </div>

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

        <Button variant="ghost" size="icon" disabled aria-label="Bildirimler (yakında)" title="Bildirimler yakında">
          <Bell className="size-5" />
        </Button>

        <div className="ml-1 flex items-center gap-2 rounded-lg py-1 pl-1 pr-2">
          <div className="grid size-8 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
            {mockUser.initials}
          </div>
          <div className="hidden leading-tight sm:block">
            <div className="text-sm font-medium">{mockUser.name}</div>
            <div className="text-xs text-muted-foreground">{mockUser.role}</div>
          </div>
        </div>
      </div>
    </header>
  );
}
