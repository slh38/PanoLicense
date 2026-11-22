import { describe, expect, it } from "vitest";
import { resolveInitialTheme } from "./theme";

describe("resolveInitialTheme", () => {
  it("kayıtlı tercihi sistem tercihinin önüne koyar", () => {
    expect(resolveInitialTheme("light", true)).toBe("light");
    expect(resolveInitialTheme("dark", false)).toBe("dark");
  });
  it("kayıt yoksa ya da bozuksa sistem tercihine düşer", () => {
    expect(resolveInitialTheme(null, true)).toBe("dark");
    expect(resolveInitialTheme("mavi", false)).toBe("light");
  });
});
