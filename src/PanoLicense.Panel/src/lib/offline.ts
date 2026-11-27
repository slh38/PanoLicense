import type { License } from "./types";

/** Lisans bazlı offline çalışma politikası (gün). Sunucu yalnız 7 veya 365 kabul eder. */
export type OfflineGraceDays = 7 | 365;

export const STANDARD_OFFLINE_DAYS: OfflineGraceDays = 7;
export const EXTENDED_OFFLINE_DAYS: OfflineGraceDays = 365;

/** 365 günlük politikayı seçme/değiştirme yetkisi (yalnız Admin; sunucuda Admin-only Gate). */
export const OFFLINE_POLICY_ABILITY = "license.offline_policy";

export function offlinePolicyLabel(days: number | null | undefined): string {
  return days === EXTENDED_OFFLINE_DAYS ? "Özel · 365 gün" : "Standart · 7 gün";
}

/** Eski yanıtlarda alan yoksa veya bilinmeyen değer gelirse standart kabul edilir (sunucu da öyle davranır). */
export function normalizeOfflineDays(days: number | null | undefined): OfflineGraceDays {
  return days === EXTENDED_OFFLINE_DAYS ? EXTENDED_OFFLINE_DAYS : STANDARD_OFFLINE_DAYS;
}

/**
 * Politika kartı (değiştirme) yalnız yetkili kullanıcıya ve yalnız aktif ticari lisansta gösterilir.
 * Onaylanmamış demo her zaman 7 gündür; iptal edilmiş lisansta değişiklik yapılmaz.
 */
export function canChangeOfflinePolicy(license: Pick<License, "status">, can: (ability: string) => boolean): boolean {
  return license.status === "active" && can(OFFLINE_POLICY_ABILITY);
}

/**
 * Oluşturma/onay isteğine eklenecek alan. Yetkisiz kullanıcı alanı hiç göndermez (sunucu varsayılanı 7);
 * yetkili kullanıcı seçtiği değeri açıkça gönderir.
 */
export function offlinePolicyBody(days: OfflineGraceDays, canChoose: boolean): { offline_grace_days?: OfflineGraceDays } {
  return canChoose ? { offline_grace_days: days } : {};
}
