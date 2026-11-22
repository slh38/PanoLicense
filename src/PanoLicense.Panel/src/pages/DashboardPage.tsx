import {
  ArrowDown,
  ArrowUp,
  Ban,
  Boxes,
  CalendarClock,
  ChevronRight,
  KeyRound,
  MonitorSmartphone,
  Plus,
  TimerReset,
  TriangleAlert,
  UserPlus,
  Users,
  XCircle,
} from "lucide-react";
import { Link } from "react-router-dom";
import { DistributionCard } from "@/components/dashboard/DistributionCard";
import { LicenseTabs } from "@/components/dashboard/LicenseTabs";
import { StatCard } from "@/components/dashboard/StatCard";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { mockActivities, mockDistribution, mockLicenses, mockStats, mockUser } from "@/data/mockDashboard";

const fmt = new Intl.NumberFormat("tr-TR");

function Change({ value }: { value: number }) {
  const up = value >= 0;
  const Icon = up ? ArrowUp : ArrowDown;
  return (
    <span className={up ? "text-success" : "text-danger"}>
      <Icon className="mr-0.5 inline size-3" />
      {up ? "+" : ""}
      {value} <span className="text-muted-foreground">(son 7 gün)</span>
    </span>
  );
}

// Hızlı işlemler ilgili sayfaya götürür; işlem formları F2'de eklenecek.
const quickActions = [
  { label: "Lisans Oluştur", icon: KeyRound, to: "/lisanslar" },
  { label: "Süre Uzat", icon: TimerReset, to: "/lisanslar" },
  { label: "Modül Değiştir", icon: Boxes, to: "/moduller" },
  { label: "Lisans İptal Et", icon: Ban, to: "/lisanslar" },
  { label: "Müşteri Ekle", icon: UserPlus, to: "/musteriler" },
];

export function DashboardPage() {
  const s = mockStats;
  return (
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
      <div className="min-w-0 space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-bold">Hoş geldin, {mockUser.firstName}</h1>
              <Badge variant="outline">Örnek veri</Badge>
            </div>
            <p className="text-sm text-muted-foreground">PanoLicense lisans sunucusu yönetim paneli</p>
          </div>
          <Button asChild>
            <Link to="/lisanslar">
              <Plus /> Yeni Lisans
            </Link>
          </Button>
        </div>

        <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
          <StatCard
            title="Aktif Lisans"
            value={fmt.format(s.activeLicenses.value)}
            icon={KeyRound}
            footer={<Change value={s.activeLicenses.change} />}
          />
          <StatCard
            title="Müşteri"
            value={fmt.format(s.customers.value)}
            icon={Users}
            tone="navy"
            footer={<Change value={s.customers.change} />}
          />
          <StatCard
            title="Aktif Cihaz"
            value={fmt.format(s.activeDevices.value)}
            icon={MonitorSmartphone}
            footer={<Change value={s.activeDevices.change} />}
          />
          <StatCard
            title="Süresi Dolacak"
            value={fmt.format(s.expiringSoon.value)}
            icon={CalendarClock}
            tone="warning"
            footer={
              <span className="text-warning">
                <TriangleAlert className="mr-0.5 inline size-3" />
                Önümüzdeki {s.expiringSoon.windowDays} gün
              </span>
            }
          />
          <StatCard
            title="Pasif / İptal"
            value={fmt.format(s.inactive.value)}
            icon={XCircle}
            tone="danger"
            footer={<Change value={s.inactive.change} />}
          />
        </div>

        <LicenseTabs licenses={mockLicenses} activities={mockActivities} />
      </div>

      <div className="grid content-start gap-5 md:grid-cols-2 xl:grid-cols-1">
        <Card>
          <CardHeader>
            <CardTitle>Hızlı İşlemler</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {quickActions.map(({ label, icon: Icon, to }) => (
              <Link
                key={label}
                to={to}
                className="flex h-10 items-center gap-3 rounded-lg border px-3 text-sm font-medium transition-colors hover:border-primary/40 hover:bg-accent hover:text-accent-foreground"
              >
                <Icon className="size-4 text-accent-foreground" />
                {label}
                <ChevronRight className="ml-auto size-4 text-muted-foreground" />
              </Link>
            ))}
          </CardContent>
        </Card>
        <DistributionCard title="Paket Dağılımı" slices={mockDistribution} />
      </div>
    </div>
  );
}
