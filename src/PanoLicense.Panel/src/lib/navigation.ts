import {
  Boxes,
  KeyRound,
  LayoutDashboard,
  MonitorSmartphone,
  ScrollText,
  Settings,
  Users,
  type LucideIcon,
} from "lucide-react";

export interface NavItem {
  path: string;
  label: string;
  icon: LucideIcon;
  /** Menüde görünmesi için gereken yetki (yoksa herkes görür). Sunucu yine kontrol eder. */
  ability?: string;
  /** Henüz geliştirilmeyen sayfalar: boş durum açıklaması ve planlanan faz. */
  description?: string;
  phase?: string;
}

export const NAV_ITEMS: NavItem[] = [
  { path: "/", label: "Dashboard", icon: LayoutDashboard },
  { path: "/lisanslar", label: "Lisanslar", icon: KeyRound, ability: "license.view" },
  { path: "/musteriler", label: "Müşteriler", icon: Users, ability: "customer.view" },
  /** Ürün, paket ve modül kataloğu (yalnız Admin). */
  { path: "/urunler", label: "Ürünler", icon: Boxes, ability: "product.manage" },
  {
    path: "/cihazlar",
    label: "Cihazlar",
    icon: MonitorSmartphone,
    ability: "device.view",
    description: "Aktive edilmiş cihazlar, son görülme zamanı ve limit kullanımı burada izlenecek.",
    phase: "F3",
  },
  { path: "/loglar", label: "Loglar", icon: ScrollText, ability: "audit.view" },
  { path: "/ayarlar", label: "Ayarlar", icon: Settings, ability: "user.manage" },
];
