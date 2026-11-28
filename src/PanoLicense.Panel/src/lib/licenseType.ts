import { formatDate } from "./format";
import type { License } from "./types";

/**
 * FAZ 6.2a.5 — süreli / ömür boyu lisans görünümü ve form kuralları (LICENSING_CONTRACT §12).
 * Asıl yetki ve dağıtım bayrağı kontrolü sunucudadır; burada yalnız görünürlük ve form doğrulaması var.
 */
export type LicenseType = "term" | "perpetual";

/** Ömür boyu oluşturma, demo onayında ömür boyu ve süreli → ömür boyu dönüşüm (yalnız Admin; sunucuda Admin-only Gate). */
export const PERPETUAL_ABILITY = "license.perpetual";

/** Dağıtım bayrakları kapalıyken sunucu ömür boyu işlemleri reddeder; panel bayrak durumunu okuyamaz. */
export const PERPETUAL_ROLLOUT_NOTE =
  "Ömür boyu lisans dağıtımı sunucuda henüz açılmadıysa işlem reddedilir ve hiçbir kayıt değişmez. Bu durumda süreli lisans verin.";

type TypeFields = Pick<License, "license_type" | "expires_at">;

/** Ömür boyu yalnız açık tür + boş bitişle geçerlidir; boş tarih tek başına ömür boyu sayılmaz. */
export function isPerpetual(l: TypeFields): boolean {
  return l.license_type === "perpetual" && l.expires_at === null;
}

/** Geçerli bitiş tarihi olan süreli (veya demo) lisans. */
export function isTerm(l: TypeFields): boolean {
  return l.license_type === "term" && !!l.expires_at;
}

export function licenseTypeLabel(l: TypeFields): string {
  if (isPerpetual(l)) return "Ömür boyu";
  if (isTerm(l)) return "Süreli";
  return "Bilinmiyor";
}

/** Liste/detay bitiş metni: süreli tarih, ömür boyu "Ömür boyu", çelişkili/eksik kayıt "—". */
export function expiryLabel(l: TypeFields): string {
  if (isPerpetual(l)) return "Ömür boyu";
  return isTerm(l) ? formatDate(l.expires_at) : "—";
}

/** Süre uzatma yalnız aktif, geçerli süreli lisansta; ömür boyunda gösterilmez. */
export function canExtend(l: TypeFields & Pick<License, "status">, can: (a: string) => boolean): boolean {
  return l.status === "active" && isTerm(l) && can("license.extend");
}

/** Tek yönlü süreli → ömür boyu dönüşüm: yalnız Admin, yalnız aktif süreli lisans (iptal/demo/ömür boyu hariç). */
export function canConvertToPerpetual(l: TypeFields & Pick<License, "status">, can: (a: string) => boolean): boolean {
  return l.status === "active" && isTerm(l) && can(PERPETUAL_ABILITY);
}

/** Oluşturma/onay isteğindeki tür alanları. Ömür boyunda bitiş açıkça null gönderilir. */
export function licenseTypeBody(type: LicenseType, expiresAt: string): { license_type: LicenseType; expires_at: string | null } {
  return type === "perpetual" ? { license_type: "perpetual", expires_at: null } : { license_type: "term", expires_at: expiresAt };
}

/**
 * İstemci tarafı ön doğrulama (sunucu kuralının aynısı): ömür boyu yalnız yetkiliye açık; süreli lisansta
 * bitiş zorunlu ve başlangıçtan sonra. Hata yoksa boş nesne.
 */
export function licenseTypeErrors(
  type: LicenseType,
  startsAt: string,
  expiresAt: string,
  canPerpetual: boolean,
): Record<string, string[]> {
  if (type === "perpetual") return canPerpetual ? {} : { license_type: ["Ömür boyu lisansı yalnız Admin verebilir."] };
  if (!expiresAt) return { expires_at: ["Süreli lisansta bitiş tarihi zorunludur."] };
  if (startsAt && expiresAt <= startsAt) return { expires_at: ["Bitiş tarihi başlangıç tarihinden sonra olmalıdır."] };
  return {};
}
