import { describe, expect, it } from "vitest";
import { describe as describeAudit } from "./audit";
import {
  canConvertToPerpetual,
  canExtend,
  expiryLabel,
  isPerpetual,
  licenseTypeBody,
  licenseTypeErrors,
  licenseTypeLabel,
  PERPETUAL_ABILITY,
} from "./licenseType";

const admin = (a: string) => [PERPETUAL_ABILITY, "license.extend", "license.create"].includes(a);
const user = (a: string) => ["license.extend", "license.create"].includes(a);

const term = { license_type: "term" as const, expires_at: "2027-12-31", status: "active" as const };
const perpetual = { license_type: "perpetual" as const, expires_at: null, status: "active" as const };

describe("süreli / ömür boyu görünümü", () => {
  it("türü ve bitişi doğru yazar", () => {
    expect(licenseTypeLabel(term)).toBe("Süreli");
    expect(expiryLabel(term)).toBe("31.12.2027");
    expect(licenseTypeLabel(perpetual)).toBe("Ömür boyu");
    expect(expiryLabel(perpetual)).toBe("Ömür boyu");
  });

  it("boş tarih tek başına ömür boyu sayılmaz; çelişkili kayıt bilinmiyor", () => {
    const broken = { license_type: "term" as const, expires_at: null };
    expect(isPerpetual(broken)).toBe(false);
    expect(licenseTypeLabel(broken)).toBe("Bilinmiyor");
    expect(expiryLabel(broken)).toBe("—");
    expect(isPerpetual({ license_type: "perpetual", expires_at: "2027-01-01" })).toBe(false);
  });
});

describe("form doğrulaması", () => {
  it("süreli lisansta bitiş zorunlu ve başlangıçtan sonra", () => {
    expect(licenseTypeErrors("term", "2026-12-01", "", true).expires_at).toBeDefined();
    expect(licenseTypeErrors("term", "2026-12-01", "2026-12-01", true).expires_at).toBeDefined();
    expect(licenseTypeErrors("term", "2026-12-01", "2027-12-01", false)).toEqual({});
  });

  it("ömür boyu tarih istemez ve yalnız Admin'e açıktır", () => {
    expect(licenseTypeErrors("perpetual", "2026-12-01", "", true)).toEqual({});
    expect(licenseTypeErrors("perpetual", "2026-12-01", "", false).license_type).toBeDefined();
  });

  it("istek gövdesi ömür boyunda açık null bitiş gönderir", () => {
    expect(licenseTypeBody("perpetual", "2027-12-31")).toEqual({ license_type: "perpetual", expires_at: null });
    expect(licenseTypeBody("term", "2027-12-31")).toEqual({ license_type: "term", expires_at: "2027-12-31" });
  });
});

describe("tek yönlü dönüşüm ve süre uzatma", () => {
  it("dönüşüm yalnız Admin'e ve yalnız aktif süreli lisansta", () => {
    expect(canConvertToPerpetual(term, admin)).toBe(true);
    expect(canConvertToPerpetual(term, user)).toBe(false);
    expect(canConvertToPerpetual(perpetual, admin)).toBe(false); // ters dönüşüm yok
    expect(canConvertToPerpetual({ ...term, status: "revoked" }, admin)).toBe(false);
    expect(canConvertToPerpetual({ ...term, status: "demo" }, admin)).toBe(false); // demo onay ekranından
  });

  it("ömür boyu ve iptal edilmiş lisansta süre uzatma yok", () => {
    expect(canExtend(term, user)).toBe(true);
    expect(canExtend(perpetual, admin)).toBe(false);
    expect(canExtend({ ...term, status: "revoked" }, admin)).toBe(false);
  });
});

describe("audit gösterimi", () => {
  const entry = (action: string, data: Record<string, unknown>) =>
    ({ id: 1, created_at: "", action, user: null, subject: null, data, ip_address: null });

  it("ömür boyu oluşturma, demo onayı ve dönüşümü ayırt eder", () => {
    expect(describeAudit(entry("license.create", { customer: "TEST", device_limit: 1, license_type: "perpetual", expires_at: null })))
      .toBe("TEST · 1 cihaz · ömür boyu");
    expect(describeAudit(entry("license.approve_demo", { customer: "TEST", license_type: "term", expires_at: "2027-12-31" })))
      .toBe("TEST · bitiş 31.12.2027");
    expect(describeAudit(entry("license.convert_perpetual", { from: "term", to: "perpetual", previous_expires_at: "2027-12-31" })))
      .toBe("süreli (bitiş 31.12.2027) → ömür boyu");
  });
});
