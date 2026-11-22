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
  it("bilinmeyen işlemde ham adı gösterir", () => {
    expect(actionLabel("yeni.islem")).toBe("yeni.islem");
    expect(actionLabel("license.revoke")).toBe("Lisans iptal etti");
  });
});
