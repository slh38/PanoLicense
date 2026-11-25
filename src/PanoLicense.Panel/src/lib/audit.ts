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
