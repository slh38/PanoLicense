import {
  Boxes,
  KeyRound,
  LayoutDashboard,
  MonitorSmartphone,
  Package,
  ScrollText,
  Settings,
  Users,
  type LucideIcon,
} from "lucide-react";

export interface NavItem {
  path: string;
  label: string;
  icon: LucideIcon;
  /** Boş durum ekranında gösterilecek açıklama ve planlanan faz. */
  description?: string;
  phase?: string;
}

export const NAV_ITEMS: NavItem[] = [
  { path: "/", label: "Dashboard", icon: LayoutDashboard },
  {
    path: "/lisanslar",
    label: "Lisanslar",
    icon: KeyRound,
    description: "Firma bazlı lisans oluşturma, süre uzatma ve iptal işlemleri burada yapılacak.",
    phase: "F2",
  },
  {
    path: "/musteriler",
    label: "Müşteriler",
    icon: Users,
    description: "Firma kayıtları ve her firmanın ürün lisansları burada listelenecek.",
    phase: "F2",
  },
  {
    path: "/moduller",
    label: "Modüller",
    icon: Boxes,
    description: "Ürünlere ait ek modüller (ör. Restoran, Üretim) burada tanımlanacak.",
    phase: "F2",
  },
  {
    path: "/paketler",
    label: "Paketler",
    icon: Package,
    description: "Lite, Pro gibi paketler; modül seti ve varsayılan cihaz kotasıyla burada tanımlanacak.",
    phase: "F2",
  },
  {
    path: "/cihazlar",
    label: "Cihazlar",
    icon: MonitorSmartphone,
    description: "Aktive edilmiş cihazlar, son görülme zamanı ve kota kullanımı burada izlenecek.",
    phase: "F3",
  },
  {
    path: "/loglar",
    label: "Loglar",
    icon: ScrollText,
    description: "Yönetim işlemleri ve aktivasyonların denetim kayıtları burada görüntülenecek.",
    phase: "F2",
  },
  {
    path: "/ayarlar",
    label: "Ayarlar",
    icon: Settings,
    description: "Kullanıcılar, roller ve sunucu ayarları burada yönetilecek.",
    phase: "F2",
  },
];
