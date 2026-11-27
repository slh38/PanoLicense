import { formatDate } from "./format";

export interface AuditEntry {
  id: number;
  created_at: string;
  action: string;
  user: { id: number; name: string } | null;
  subject: { type: string; id: number; label: string | null } | null;
  data: Record<string, unknown> | null;
  ip_address: string | null;
}

export const ACTION_LABEL: Record<string, string> = {
  "auth.login": "Giriş yaptı",
  "auth.logout": "Çıkış yaptı",
  "auth.login_failed": "Başarısız giriş",
  "license.create": "Lisans verdi",
  "license.extend": "Süre uzattı",
  "license.revoke": "Lisans iptal etti",
  "license.demo_register": "PanoPOS demo kuruldu",
  "license.approve_demo": "Demoyu onayladı",
  "license.device_activate": "Cihaz aktive edildi (PanoPOS)",
  "license.device_release": "Cihazı serbest bıraktı",
  "license.offline_policy_change": "Offline politikayı değiştirdi",
  "license.package_change": "Paketi değiştirdi",
  "license.modules_change": "Ek modülleri değiştirdi",
  "catalog.product_create": "Ürün ekledi",
  "catalog.product_update": "Ürünü güncelledi",
  "catalog.package_create": "Paket ekledi",
  "catalog.package_update": "Paketi güncelledi",
  "catalog.module_create": "Modül ekledi",
  "catalog.module_update": "Modülü güncelledi",
  "customer.create": "Firma ekledi",
  "user.create": "Kullanıcı oluşturdu",
  "user.update": "Kullanıcı güncelledi",
  "user.admin_create": "Admin oluşturuldu (sunucu)",
  "user.admin_password_reset": "Admin parolası sıfırlandı (sunucu)",
};

export const GROUPS = [
  { value: "", label: "Tüm işlemler" },
  { value: "auth", label: "Giriş / çıkış" },
  { value: "license", label: "Lisans" },
  { value: "customer", label: "Firma" },
  { value: "user", label: "Kullanıcı" },
  { value: "catalog", label: "Ürün / paket / modül" },
];

/** İşlemi yapan: panel kullanıcısı; yoksa istemciden gelen işlemlerde "PanoPOS". */
export function actorName(entry: AuditEntry): string {
  if (entry.user) return entry.user.name;
  if (entry.action === "license.demo_register" || entry.action === "license.device_activate") return "PanoPOS";
  return "—";
}

export function actionLabel(action: string): string {
  return ACTION_LABEL[action] ?? action;
}

export function isWarning(action: string): boolean {
  return action === "auth.login_failed" || action === "license.revoke";
}

/** Kayıt verisinden kısa, okunur özet (gizli değer zaten saklanmaz). */
export function describe(entry: AuditEntry): string {
  const d = entry.data ?? {};
  switch (entry.action) {
    case "license.create":
      return [d.customer, d.device_limit ? `${d.device_limit} cihaz` : null, d.expires_at ? `bitiş ${formatDate(String(d.expires_at))}` : null]
        .filter(Boolean)
        .join(" · ");
    case "license.extend":
      return `${formatDate(String(d.from ?? ""))} → ${formatDate(String(d.to ?? ""))}`;
    case "license.revoke":
      return `Neden: ${d.reason ?? "—"}`;
    case "license.demo_register":
      return [d.customer, d.installer ? `kuran: ${d.installer}${d.installer_matched ? "" : " (panelde yok)"}` : "kuran: son kullanıcı", d.device]
        .filter(Boolean)
        .join(" · ");
    case "license.approve_demo":
      return [d.customer, d.device_limit ? `${d.device_limit} cihaz` : null, d.expires_at ? `bitiş ${formatDate(String(d.expires_at))}` : null]
        .filter(Boolean)
        .join(" · ");
    case "license.device_activate":
      return [d.device, d.device_limit ? `${d.active_devices}/${d.device_limit}` : null].filter(Boolean).join(" · ");
    case "license.device_release":
      return String(d.device ?? "");
    case "license.package_change":
      return `${d.from ?? "—"} → ${d.to ?? "—"}`;
    case "license.modules_change": {
      const list = (v: unknown) => (Array.isArray(v) && v.length ? v.join(", ") : null);
      return [list(d.added) && `eklendi: ${list(d.added)}`, list(d.removed) && `kaldırıldı: ${list(d.removed)}`].filter(Boolean).join(" · ");
    }
    case "catalog.product_create":
    case "catalog.package_create":
    case "catalog.module_create":
    case "catalog.product_update":
    case "catalog.package_update":
    case "catalog.module_update": {
      const parts = [d.product, d.code].filter(Boolean).join(" · ");
      const mods = [Array.isArray(d.modules_added) && d.modules_added.length ? `+${d.modules_added.join(", ")}` : null,
        Array.isArray(d.modules_removed) && d.modules_removed.length ? `-${d.modules_removed.join(", ")}` : null].filter(Boolean).join(" ");
      return [parts, mods].filter(Boolean).join(" · ");
    }
    case "license.offline_policy_change":
      return `${d.from ?? "?"} gün → ${d.to ?? "?"} gün`;
    case "customer.create":
      return String(d.name ?? "");
    case "auth.login_failed":
      return `Denenen kullanıcı adı: ${d.username ?? "—"}`;
    case "user.create":
      return `@${d.username ?? ""}`;
    case "user.update": {
      const parts: string[] = [];
      if (Array.isArray(d.fields) && d.fields.length) parts.push(`alanlar: ${d.fields.join(", ")}`);
      if (d.password_changed) parts.push("parola değişti");
      if (Array.isArray(d.permissions)) parts.push(`${d.permissions.length} yetki`);
      return parts.join(" · ");
    }
    default:
      return "";
  }
}
