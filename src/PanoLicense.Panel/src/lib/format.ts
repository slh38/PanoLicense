import type { LicenseState } from "./types";

/** "2026-12-31" → "31.12.2026" (saat dilimi kaymasından etkilenmez). */
export function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  const [y, m, d] = value.slice(0, 10).split("-");
  return y && m && d ? `${d}.${m}.${y}` : value;
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  return new Date(value).toLocaleString("tr-TR", { dateStyle: "short", timeStyle: "short" });
}

export const STATE_LABEL: Record<LicenseState, string> = {
  active: "Aktif",
  expiring: "Süresi Dolacak",
  expired: "Süresi Doldu",
  revoked: "İptal",
};

export const STATE_VARIANT: Record<LicenseState, "success" | "warning" | "danger" | "outline"> = {
  active: "success",
  expiring: "warning",
  expired: "danger",
  revoked: "outline",
};

/** Bugünden itibaren gün ekleyip YYYY-MM-DD döner (yerel tarih). */
export function isoDatePlus(days: number, from = new Date()): string {
  const d = new Date(from.getFullYear(), from.getMonth(), from.getDate() + days);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
