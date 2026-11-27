import { CloudOff, Lock, TriangleAlert } from "lucide-react";
import { EXTENDED_OFFLINE_DAYS, STANDARD_OFFLINE_DAYS, type OfflineGraceDays } from "@/lib/offline";
import { cn } from "@/lib/utils";

const OPTIONS: { days: OfflineGraceDays; title: string; text: string }[] = [
  { days: STANDARD_OFFLINE_DAYS, title: "Standart · 7 gün", text: "Önerilen. Cihaz en az haftada bir internete bağlanır." },
  { days: EXTENDED_OFFLINE_DAYS, title: "Özel · 365 gün", text: "İnterneti çok nadir olan işletmeler için." },
];

/** 365 gün seçiliyken gösterilen kısa uyarı. */
export function OfflineRevokeWarning() {
  return (
    <div role="note" className="flex gap-2 rounded-lg bg-warning-soft px-3 py-2 text-xs text-warning">
      <TriangleAlert className="mt-0.5 size-4 shrink-0" />
      <span>
        Cihaz internete bağlanmazsa lisans iptali veya politika değişikliği, cihazdaki 365 günlük offline izin bitene kadar
        etkili olmayabilir (izin lisans bitişini aşmaz).
      </span>
    </div>
  );
}

/**
 * Offline çalışma politikası seçimi (7 / 365 gün).
 * Yetkisi olmayan kullanıcıda 365 seçeneği kilitlidir; asıl kontrol sunucudadır.
 */
export function OfflinePolicyPicker({
  value,
  onChange,
  canChooseExtended,
  lockedReason = "365 günlük politikayı yalnız Admin seçebilir.",
  error,
  disabled,
}: {
  value: OfflineGraceDays;
  onChange: (days: OfflineGraceDays) => void;
  canChooseExtended: boolean;
  lockedReason?: string;
  error?: string;
  disabled?: boolean;
}) {
  return (
    <fieldset className="space-y-2" disabled={disabled}>
      <legend className="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
        <CloudOff className="size-3.5" /> Offline çalışma
      </legend>
      <div role="radiogroup" aria-label="Offline çalışma politikası" className="grid gap-2 sm:grid-cols-2">
        {OPTIONS.map((o) => {
          const locked = o.days === EXTENDED_OFFLINE_DAYS && !canChooseExtended;
          const selected = value === o.days;
          return (
            <button
              key={o.days}
              type="button"
              role="radio"
              aria-checked={selected}
              disabled={locked || disabled}
              title={locked ? lockedReason : undefined}
              onClick={() => onChange(o.days)}
              className={cn(
                "rounded-lg border px-3 py-2.5 text-left transition-colors disabled:cursor-not-allowed",
                selected ? "border-primary bg-accent" : "bg-card hover:bg-muted/60",
                locked && "opacity-60 hover:bg-card",
              )}
            >
              <span className="flex items-center justify-between gap-2 text-sm font-medium">
                <span className={selected ? "text-accent-foreground" : ""}>{o.title}</span>
                {locked ? (
                  <Lock className="size-3.5 text-muted-foreground" />
                ) : (
                  <span
                    className={cn(
                      "size-3.5 rounded-full border",
                      selected ? "border-4 border-primary" : "border-muted-foreground/50",
                    )}
                  />
                )}
              </span>
              <span className="mt-0.5 block text-xs text-muted-foreground">{locked ? lockedReason : o.text}</span>
            </button>
          );
        })}
      </div>
      {error && <span className="block text-xs text-danger">{error}</span>}
      {value === EXTENDED_OFFLINE_DAYS && <OfflineRevokeWarning />}
    </fieldset>
  );
}
