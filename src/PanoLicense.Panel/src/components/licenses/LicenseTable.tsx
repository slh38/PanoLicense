import { Store } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { formatDate, installerLabel, STATE_LABEL, STATE_VARIANT } from "@/lib/format";
import type { License } from "@/lib/types";

/**
 * Lisans listesi. "Veren" lisansı kimin verdiğini (panelden veren veya demoyu onaylayan),
 * "Kuran" PanoPOS demo kurulumunu kimin yaptığını gösterir.
 */
export function LicenseTable({ rows, empty }: { rows: License[]; empty: string }) {
  const navigate = useNavigate();

  if (rows.length === 0) {
    return <p className="px-4 py-10 text-center text-sm text-muted-foreground">{empty}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[920px] text-sm">
        <thead>
          <tr className="border-b text-left text-xs text-muted-foreground">
            <th className="px-4 py-2.5 font-medium">Firma</th>
            <th className="px-4 py-2.5 font-medium">Lisans Kodu</th>
            <th className="px-4 py-2.5 font-medium">Ürün / Paket</th>
            <th className="px-4 py-2.5 font-medium">Cihaz</th>
            <th className="px-4 py-2.5 font-medium">Bitiş</th>
            <th className="px-4 py-2.5 font-medium">Durum</th>
            <th className="px-4 py-2.5 font-medium">Kuran</th>
            <th className="px-4 py-2.5 font-medium">Veren</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((l) => (
            <tr
              key={l.id}
              tabIndex={0}
              onClick={() => navigate(`/lisanslar/${l.id}`)}
              onKeyDown={(e) => e.key === "Enter" && navigate(`/lisanslar/${l.id}`)}
              className="cursor-pointer border-b last:border-0 hover:bg-muted/50 focus:bg-muted/50 focus:outline-none"
            >
              <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                  <div className="grid size-9 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
                    <Store className="size-4" />
                  </div>
                  <div className="min-w-0">
                    <div className="truncate font-medium">{l.customer.name}</div>
                    <div className="truncate text-xs text-muted-foreground">{l.customer.city ?? "—"}</div>
                  </div>
                </div>
              </td>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">{l.license_key}</td>
              <td className="px-4 py-3">
                <Badge>{l.product.name}</Badge>
                {l.package && <div className="mt-1 text-xs text-muted-foreground">{l.package.name}</div>}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums">
                {l.active_device_count !== undefined ? `${l.active_device_count}/${l.device_limit}` : l.device_limit}
              </td>
              <td className="whitespace-nowrap px-4 py-3 tabular-nums">{formatDate(l.expires_at)}</td>
              <td className="px-4 py-3">
                <Badge variant={STATE_VARIANT[l.state]}>{STATE_LABEL[l.state]}</Badge>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{l.source === "demo" ? installerLabel(l.installer) : "—"}</td>
              <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{l.created_by?.name ?? "Onay bekliyor"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
