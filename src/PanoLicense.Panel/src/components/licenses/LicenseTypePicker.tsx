import { CalendarClock, Infinity as InfinityIcon, Info, Lock } from "lucide-react";
import { PERPETUAL_ROLLOUT_NOTE, type LicenseType } from "@/lib/licenseType";
import { cn } from "@/lib/utils";

const OPTIONS: { type: LicenseType; title: string; text: string }[] = [
  { type: "term", title: "Süreli lisans", text: "Bitiş tarihine kadar geçerli; süre uzatılabilir." },
  { type: "perpetual", title: "Ömür boyu lisans", text: "Bitiş tarihi yok. Süreli lisansa geri çevrilemez." },
];

/** Ömür boyu seçiliyken dağıtım bayrağı bilgisi. */
export function PerpetualRolloutNote() {
  return (
    <div role="note" className="flex gap-2 rounded-lg bg-accent px-3 py-2 text-xs text-accent-foreground">
      <Info className="mt-0.5 size-4 shrink-0" />
      <span>{PERPETUAL_ROLLOUT_NOTE}</span>
    </div>
  );
}

/**
 * Lisans türü seçimi (FAZ 6.2a.5). Ömür boyu yalnız Admin'e açıktır; User'da kilitli gösterilir.
 * Asıl kontrol sunucudadır (license.perpetual Gate + dağıtım bayrakları).
 */
export function LicenseTypePicker({
  value,
  onChange,
  canPerpetual,
  error,
  disabled,
}: {
  value: LicenseType;
  onChange: (type: LicenseType) => void;
  canPerpetual: boolean;
  error?: string;
  disabled?: boolean;
}) {
  return (
    <fieldset className="space-y-2" disabled={disabled}>
      <legend className="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
        <CalendarClock className="size-3.5" /> Lisans türü
      </legend>
      <div role="radiogroup" aria-label="Lisans türü" className="grid gap-2 sm:grid-cols-2">
        {OPTIONS.map((o) => {
          const locked = o.type === "perpetual" && !canPerpetual;
          const selected = value === o.type;
          return (
            <button
              key={o.type}
              type="button"
              role="radio"
              aria-checked={selected}
              disabled={locked || disabled}
              title={locked ? "Ömür boyu lisansı yalnız Admin verebilir." : undefined}
              onClick={() => onChange(o.type)}
              className={cn(
                "rounded-lg border px-3 py-2.5 text-left transition-colors disabled:cursor-not-allowed",
                selected ? "border-primary bg-accent" : "bg-card hover:bg-muted/60",
                locked && "opacity-60 hover:bg-card",
              )}
            >
              <span className="flex items-center justify-between gap-2 text-sm font-medium">
                <span className={cn("inline-flex items-center gap-1.5", selected && "text-accent-foreground")}>
                  {o.type === "perpetual" && <InfinityIcon className="size-3.5" />}
                  {o.title}
                </span>
                {locked ? (
                  <Lock className="size-3.5 text-muted-foreground" />
                ) : (
                  <span className={cn("size-3.5 rounded-full border", selected ? "border-4 border-primary" : "border-muted-foreground/50")} />
                )}
              </span>
              <span className="mt-0.5 block text-xs text-muted-foreground">
                {locked ? "Ömür boyu lisansı yalnız Admin verebilir." : o.text}
              </span>
            </button>
          );
        })}
      </div>
      {error && <span className="block text-xs text-danger">{error}</span>}
      {value === "perpetual" && <PerpetualRolloutNote />}
    </fieldset>
  );
}
