import { ShieldCheck } from "lucide-react";
import { NavLink } from "react-router-dom";
import { NAV_ITEMS } from "@/lib/navigation";
import { cn } from "@/lib/utils";

interface SidebarProps {
  collapsed: boolean;
  mobileOpen: boolean;
  onNavigate: () => void;
}

export function Sidebar({ collapsed, mobileOpen, onNavigate }: SidebarProps) {
  return (
    <aside
      className={cn(
        "fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r bg-sidebar transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0",
        mobileOpen ? "translate-x-0" : "-translate-x-full",
        collapsed && "lg:w-[68px]",
      )}
      aria-label="Ana menü"
    >
      <div className="flex h-16 items-center gap-3 px-4">
        <div className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground">
          <ShieldCheck className="size-5" />
        </div>
        <div className={cn("min-w-0 leading-tight", collapsed && "lg:hidden")}>
          <div className="truncate text-base font-bold">PanoLicense</div>
          <div className="truncate text-xs text-muted-foreground">Lisans Yönetimi</div>
        </div>
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-2">
        {NAV_ITEMS.map(({ path, label, icon: Icon }) => (
          <NavLink
            key={path}
            to={path}
            end={path === "/"}
            onClick={onNavigate}
            title={collapsed ? label : undefined}
            className={({ isActive }) =>
              cn(
                "flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors",
                isActive
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "text-muted-foreground hover:bg-accent hover:text-accent-foreground",
                collapsed && "lg:justify-center lg:px-0",
              )
            }
          >
            <Icon className="size-[18px] shrink-0" />
            <span className={cn("truncate", collapsed && "lg:hidden")}>{label}</span>
          </NavLink>
        ))}
      </nav>

      <div className={cn("border-t p-4 text-xs text-muted-foreground", collapsed && "lg:hidden")}>
        Sürüm 0.1.0 · F0
      </div>
    </aside>
  );
}
