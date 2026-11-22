import { describe, expect, it } from "vitest";
import { formatDate, isoDatePlus } from "./format";

describe("formatDate", () => {
  it("ISO tarihi Türkçe biçime çevirir", () => {
    expect(formatDate("2026-12-31")).toBe("31.12.2026");
    expect(formatDate("2026-12-31T23:00:00Z")).toBe("31.12.2026");
  });
  it("boş değerde tire döner", () => {
    expect(formatDate(null)).toBe("—");
  });
});

describe("isoDatePlus", () => {
  it("yıl sınırını doğru geçer", () => {
    expect(isoDatePlus(10, new Date(2026, 11, 25))).toBe("2027-01-04");
  });
});
