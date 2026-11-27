import { Package } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Field } from "@/components/ui/input";
import type { ProductPackage } from "@/lib/types";

/** Paket seçimi + paketin modülleri (ön izleme). Lisansa özel ek modüller detayda Admin tarafından verilir. */
export function PackagePicker({
  packages,
  value,
  onChange,
  error,
}: {
  packages: ProductPackage[];
  value: number | "";
  onChange: (pkg: ProductPackage) => void;
  error?: string;
}) {
  const selected = packages.find((p) => p.id === value);

  return (
    <div className="space-y-2">
      <Field label="Paket *" error={error}>
        <select
          value={value}
          onChange={(e) => {
            const pkg = packages.find((p) => p.id === Number(e.target.value));
            if (pkg) onChange(pkg);
          }}
          className="h-9 w-full rounded-lg border bg-card px-3 text-sm outline-none focus:border-primary"
        >
          {value === "" && <option value="">Paket seçin</option>}
          {packages.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </Field>
      {selected && (
        <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
          <Package className="size-3.5" />
          {selected.modules.length ? (
            selected.modules.map((m) => (
              <Badge key={m.code} variant="outline">
                {m.name}
              </Badge>
            ))
          ) : (
            <span>Bu pakette modül tanımlı değil.</span>
          )}
        </div>
      )}
    </div>
  );
}
