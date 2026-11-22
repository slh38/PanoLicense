import { describe, expect, it } from "vitest";
import { ApiError, readCookie } from "./api";

describe("readCookie", () => {
  it("XSRF-TOKEN değerini URL çözümlemesiyle okur", () => {
    expect(readCookie("a=1; XSRF-TOKEN=abc%3D%3D; b=2", "XSRF-TOKEN")).toBe("abc==");
  });
  it("benzer adlı cookie ile karışmaz", () => {
    expect(readCookie("MY-XSRF-TOKEN=zzz", "XSRF-TOKEN")).toBeNull();
  });
});

describe("ApiError.firstMessage", () => {
  it("alan hatasını genel mesajın önüne koyar", () => {
    const err = new ApiError(422, "Geçersiz", { username: ["Kullanıcı adı veya parola hatalı."] });
    expect(err.firstMessage()).toBe("Kullanıcı adı veya parola hatalı.");
  });
  it("alan hatası yoksa genel mesajı döner", () => {
    expect(new ApiError(403, "Yetkiniz yok").firstMessage()).toBe("Yetkiniz yok");
  });
});
