import { describe as suite, expect, it } from "vitest";
import { actionLabel, describe, type AuditEntry } from "./audit";

const entry = (action: string, data: Record<string, unknown>): AuditEntry => ({
  id: 1,
  created_at: "2026-11-22T20:00:00Z",
  action,
  user: null,
  subject: null,
  data,
  ip_address: null,
});

suite("audit özetleri", () => {
  it("lisans verme özetini firma, cihaz ve bitişle yazar", () => {
    expect(describe(entry("license.create", { customer: "Ada Market", device_limit: 3, expires_at: "2027-11-22" }))).toBe(
      "Ada Market · 3 cihaz · bitiş 22.11.2027",
    );
  });
  it("süre uzatmayı eski → yeni tarih olarak yazar", () => {
    expect(describe(entry("license.extend", { from: "2027-01-01", to: "2028-01-01" }))).toBe("01.01.2027 → 01.01.2028");
  });
  it("offline politika değişimini eski → yeni gün olarak yazar", () => {
    expect(describe(entry("license.offline_policy_change", { from: 7, to: 365 }))).toBe("7 gün → 365 gün");
    expect(actionLabel("license.offline_policy_change")).toBe("Offline politikayı değiştirdi");
  });
  it("paket ve ek modül değişikliklerini okunur yazar", () => {
    expect(describe(entry("license.package_change", { from: "PRO", to: "LITE" }))).toBe("PRO → LITE");
    expect(describe(entry("license.modules_change", { added: ["backup"], removed: [] }))).toBe("eklendi: backup");
    expect(describe(entry("catalog.package_update", { code: "PRO", modules_added: ["variant"], modules_removed: ["shift"] }))).toBe(
      "PRO · +variant -shift",
    );
    expect(actionLabel("license.modules_change")).toBe("Ek modülleri değiştirdi");
  });
  it("bilinmeyen işlemde ham adı gösterir", () => {
    expect(actionLabel("yeni.islem")).toBe("yeni.islem");
    expect(actionLabel("license.revoke")).toBe("Lisans iptal etti");
  });
});
