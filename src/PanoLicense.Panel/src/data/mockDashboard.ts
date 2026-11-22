// ÖRNEK VERİ — gerçek API'ye bağlı değildir. Firma adları uydurmadır.
// F2'de bu dosya kaldırılıp /api uçlarından okunacak.

export const mockUser = { name: "Salih Kocamaz", firstName: "Salih", initials: "SK", role: "Admin" };

export const mockStats = {
  activeLicenses: { value: 642, change: 12 },
  customers: { value: 588, change: 8 },
  activeDevices: { value: 1842, change: 37 },
  expiringSoon: { value: 27, windowDays: 30 },
  inactive: { value: 18, change: -3 },
};

export type LicenseStatus = "Aktif" | "Süresi Dolacak" | "İptal";

export interface MockLicense {
  customer: string;
  city: string;
  key: string;
  product: string;
  pkg: "Lite" | "Pro" | "Restaurant" | "Plus";
  modules: string[];
  devices: { used: number; max: number };
  expiresAt: string;
  status: LicenseStatus;
}

export const mockLicenses: MockLicense[] = [
  {
    customer: "Ada Market",
    city: "Kayseri / Kocasinan",
    key: "PAN-8F3D-4E21-9C7A",
    product: "PanoPOS",
    pkg: "Pro",
    modules: ["Satış", "Stok"],
    devices: { used: 2, max: 3 },
    expiresAt: "31.12.2026",
    status: "Aktif",
  },
  {
    customer: "Lezzet Restoran",
    city: "Kayseri / Melikgazi",
    key: "PAN-7A11-9D2B-4F6E",
    product: "PanoPOS",
    pkg: "Restaurant",
    modules: ["Restoran", "Rapor"],
    devices: { used: 4, max: 5 },
    expiresAt: "15.12.2026",
    status: "Süresi Dolacak",
  },
  {
    customer: "Hızlı AVM",
    city: "Kayseri / Talas",
    key: "PAN-5C90-2B7E-11D4",
    product: "PanoPOS",
    pkg: "Plus",
    modules: ["Satış", "Üretim"],
    devices: { used: 6, max: 10 },
    expiresAt: "28.02.2027",
    status: "Aktif",
  },
  {
    customer: "Yıldız Büfe",
    city: "Kayseri / Hacılar",
    key: "PAN-2E64-8A0C-73B9",
    product: "PanoPOS",
    pkg: "Lite",
    modules: ["Satış"],
    devices: { used: 1, max: 1 },
    expiresAt: "08.12.2026",
    status: "Süresi Dolacak",
  },
  {
    customer: "Merkez Toptan",
    city: "Kayseri / Kocasinan",
    key: "PAN-9B02-6C5F-E418",
    product: "PanoPOS",
    pkg: "Pro",
    modules: ["Satış", "Stok", "Rapor"],
    devices: { used: 3, max: 3 },
    expiresAt: "20.06.2027",
    status: "Aktif",
  },
];

export interface MockActivity {
  time: string;
  text: string;
  kind: "create" | "extend" | "device" | "revoke";
}

export const mockActivities: MockActivity[] = [
  { time: "Bugün 14:32", text: "Merkez Toptan için Pro lisans oluşturuldu", kind: "create" },
  { time: "Bugün 11:05", text: "Hızlı AVM lisansına yeni cihaz aktive edildi (6/10)", kind: "device" },
  { time: "Dün 17:48", text: "Ada Market lisans süresi 31.12.2026'ya uzatıldı", kind: "extend" },
  { time: "Dün 09:12", text: "Demo Kafe lisansı iptal edildi", kind: "revoke" },
];

export const mockDistribution = [
  { label: "Lite", value: 214, color: "var(--chart-3)" },
  { label: "Pro", value: 238, color: "var(--chart-2)" },
  { label: "Restaurant", value: 118, color: "var(--chart-4)" },
  { label: "Plus", value: 72, color: "var(--chart-1)" },
];
