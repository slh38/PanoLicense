import { Ban, CalendarClock, KeyRound, MonitorSmartphone, ShoppingCart, Store, UtensilsCrossed } from "lucide-react";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import type { LicenseStatus, MockActivity, MockLicense } from "@/data/mockDashboard";
import { cn } from "@/lib/utils";

const statusVariant: Record<LicenseStatus, "success" | "warning" | "danger"> = {
  Aktif: "success",
  "Süresi Dolacak": "warning",
  İptal: "danger",
};

const activityIcon: Record<MockActivity["kind"], typeof KeyRound> = {
  create: KeyRound,
  extend: CalendarClock,
  device: MonitorSmartphone,
  revoke: Ban,
};

function CustomerIcon({ pkg }: { pkg: MockLicense["pkg"] }) {
  const Icon = pkg === "Restaurant" ? UtensilsCrossed : pkg === "Plus" ? ShoppingCart : Store;
  return (
    <div className="grid size-9 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
      <Icon className="size-4" />
    </div>
  );
}

function LicenseTable({ rows }: { rows: MockLicense[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[760px] text-sm">
        <thead>
          <tr className="border-b text-left text-xs text-muted-foreground">
            <th className="px-4 py-2.5 font-medium">Müşteri</th>
            <th className="px-4 py-2.5 font-medium">Lisans Kodu</th>
            <th className="px-4 py-2.5 font-medium">Paket</th>
            <th className="px-4 py-2.5 font-medium">Modüller</th>
            <th className="px-4 py-2.5 font-medium">Cihaz</th>
            <th className="px-4 py-2.5 font-medium">Bitiş Tarihi</th>
            <th className="px-4 py-2.5 font-medium">Durum</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((l) => (
            <tr key={l.key} className="border-b last:border-0 hover:bg-muted/50">
              <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                  <CustomerIcon pkg={l.pkg} />
                  <div className="min-w-0">
                    <div className="truncate font-medium">{l.customer}</div>
                    <div className="truncate text-xs text-muted-foreground">{l.city}</div>
                  </div>
                </div>
              </td>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{l.key}</td>
              <td className="px-4 py-3">
                <Badge variant={l.pkg === "Plus" ? "solid" : "default"}>{l.pkg}</Badge>
              </td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                  {l.modules.map((m) => (
                    <Badge key={m} variant="outline">
                      {m}
                    </Badge>
                  ))}
                </div>
              </td>
              <td className="px-4 py-3 tabular-nums">
                {l.devices.used}/{l.devices.max}
              </td>
              <td className="px-4 py-3 tabular-nums">{l.expiresAt}</td>
              <td className="px-4 py-3">
                <Badge variant={statusVariant[l.status]}>{l.status}</Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ActivityList({ items }: { items: MockActivity[] }) {
  return (
    <ul className="divide-y">
      {items.map((a) => {
        const Icon = activityIcon[a.kind];
        return (
          <li key={a.time + a.text} className="flex items-center gap-3 px-4 py-3 text-sm">
            <div
              className={cn(
                "grid size-8 shrink-0 place-items-center rounded-lg",
                a.kind === "revoke" ? "bg-danger-soft text-danger" : "bg-accent text-accent-foreground",
              )}
            >
              <Icon className="size-4" />
            </div>
            <span className="min-w-0 flex-1">{a.text}</span>
            <span className="whitespace-nowrap text-xs text-muted-foreground">{a.time}</span>
          </li>
        );
      })}
    </ul>
  );
}

export function LicenseTabs({ licenses, activities }: { licenses: MockLicense[]; activities: MockActivity[] }) {
  const active = licenses.filter((l) => l.status === "Aktif");
  const expiring = licenses.filter((l) => l.status === "Süresi Dolacak");
  const tabs = [
    { id: "active", label: "Aktif Lisanslar", content: <LicenseTable rows={active} /> },
    { id: "expiring", label: `Süresi Dolacak (${expiring.length})`, content: <LicenseTable rows={expiring} /> },
    { id: "activity", label: "Son İşlemler", content: <ActivityList items={activities} /> },
  ];
  const [current, setCurrent] = useState(tabs[0].id);

  return (
    <Card className="overflow-hidden">
      <div role="tablist" className="flex gap-1 overflow-x-auto overflow-y-hidden border-b px-3 pt-3 [scrollbar-width:none]">
        {tabs.map((t) => (
          <button
            key={t.id}
            role="tab"
            type="button"
            aria-selected={current === t.id}
            onClick={() => setCurrent(t.id)}
            className={cn(
              "whitespace-nowrap rounded-t-lg border-b-2 px-3 pb-2.5 pt-2 text-sm font-medium transition-colors",
              current === t.id
                ? "border-primary text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        ))}
      </div>
      <div role="tabpanel">{tabs.find((t) => t.id === current)?.content}</div>
    </Card>
  );
}
