import { describe, expect, it } from "vitest";
import { toPercentages } from "./percent";

describe("toPercentages", () => {
  it("yuvarlama sonrası toplamı 100'de tutar", () => {
    const result = toPercentages([214, 238, 118, 72]);
    expect(result.reduce((a, b) => a + b, 0)).toBe(100);
    expect(result).toEqual([33, 37, 19, 11]);
  });

  it("eşit parçalarda toplam 100 olur", () => {
    expect(toPercentages([1, 1, 1])).toEqual([34, 33, 33]);
  });

  it("toplam sıfırsa sıfır döner", () => {
    expect(toPercentages([0, 0])).toEqual([0, 0]);
  });
});
