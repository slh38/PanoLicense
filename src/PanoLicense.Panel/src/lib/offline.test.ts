import { describe, expect, it } from "vitest";
import { canChangeOfflinePolicy, normalizeOfflineDays, OFFLINE_POLICY_ABILITY, offlinePolicyBody, offlinePolicyLabel } from "./offline";

const admin = (a: string) => a === OFFLINE_POLICY_ABILITY || a === "license.create";
const user = (a: string) => a === "license.create";

describe("offline politika", () => {
  it("etiketi 7 / 365 gün olarak yazar, bilinmeyeni standart sayar", () => {
    expect(offlinePolicyLabel(7)).toBe("Standart · 7 gün");
    expect(offlinePolicyLabel(365)).toBe("Özel · 365 gün");
    expect(offlinePolicyLabel(undefined)).toBe("Standart · 7 gün");
    expect(normalizeOfflineDays(30)).toBe(7);
  });

  it("politika yalnız Admin tarafından ve yalnız aktif ticari lisansta değiştirilir", () => {
    expect(canChangeOfflinePolicy({ status: "active" }, admin)).toBe(true);
    expect(canChangeOfflinePolicy({ status: "active" }, user)).toBe(false);
    expect(canChangeOfflinePolicy({ status: "demo" }, admin)).toBe(false);
    expect(canChangeOfflinePolicy({ status: "revoked" }, admin)).toBe(false);
  });

  it("yetkisiz kullanıcı alanı hiç göndermez; Admin seçimini açıkça gönderir", () => {
    expect(offlinePolicyBody(365, false)).toEqual({});
    expect(offlinePolicyBody(7, true)).toEqual({ offline_grace_days: 7 });
    expect(offlinePolicyBody(365, true)).toEqual({ offline_grace_days: 365 });
  });
});
