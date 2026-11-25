import { CalendarClock, ChevronRight, KeyRound, MonitorDown, MonitorSmartphone, Plus, Search, UserPlus, Users, XCircle } from "lucide-react";
import { Link } from "react-router-dom";
import { DistributionCard } from "@/components/dashboard/DistributionCard";
import { StatCard } from "@/components/dashboard/StatCard";
import { LicenseTable } from "@/components/licenses/LicenseTable";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert } from "@/components/ui/input";
import { useAuth } from "@/lib/auth";
import type { License } from "@/lib/types";
import { useApi } from "@/lib/useApi";

interface Dashboard {
  scope: "all" | "own";
  expiring_window_days: number;
  stats: {
    expiring: number;
    created_by_me: number;
    demo_pending: number;
    active?: number;
    customers?: number;
    devices?: number;
    inactive?: number;
  };
  recent: License[];
  distribution?: { label: string; value: number }[];
}

const fmt = new Intl.NumberFormat("tr-TR");
const CHART_COLORS = ["var(--chart-2)", "var(--chart-3)", "var(--chart-4)", "var(--chart-1)"];

export function DashboardPage() {
  const { me, can } = useAuth();
  const { data, error, loading } = useApi<Dashboard>("/dashboard");
  const isAdmin = data?.scope === "all";

  const quickActions = [
    { label: "Yeni Lisans", icon: KeyRound, to: "/lisanslar/yeni", ability: "license.create" },
    { label: "Demo Talepleri", icon: MonitorDown, to: "/lisanslar?scope=demo", ability: "license.view" },
    { label: "Süresi Dolacaklar", icon: CalendarClock, to: "/lisanslar?scope=expiring", ability: "license.view" },
    { label: "Lisans Ara", icon: Search, to: "/lisanslar", ability: "license.view" },
    { label: "Kullanıcı Ekle", icon: UserPlus, to: "/ayarlar", ability: "user.manage" },
  ].filter((a) => can(a.ability));

  return (
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
      <div className="min-w-0 space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold">Hoş geldin, {me?.name.split(" ")[0]}</h1>
            <p className="text-sm text-muted-foreground">PanoLicense lisans sunucusu yönetim paneli</p>
          </div>
          {can("license.create") && (
            <Button asChild>
              <Link to="/lisanslar/yeni">
                <Plus /> Yeni Lisans
              </Link>
            </Button>
          )}
        </div>

        {error && <Alert>{error}</Alert>}

        {data && (
          <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
            {isAdmin && (
              <>
                <StatCard title="Aktif Lisans" value={fmt.format(data.stats.active ?? 0)} icon={KeyRound} footer={<span className="text-muted-foreground">Süresi dolmamış</span>} />
                <StatCard title="Müşteri" value={fmt.format(data.stats.customers ?? 0)} icon={Users} tone="navy" footer={<span className="text-muted-foreground">Aktif firma</span>} />
                <StatCard title="Aktif Cihaz" value={fmt.format(data.stats.devices ?? 0)} icon={MonitorSmartphone} footer={<span className="text-muted-foreground">Tüm lisanslarda</span>} />
              </>
            )}
            <Link to="/lisanslar?scope=demo" className="rounded-xl focus-visible:outline-2">
              <StatCard
                title="Demo Talepleri"
                value={fmt.format(data.stats.demo_pending)}
                icon={MonitorDown}
                footer={<span className="text-muted-foreground">Onay bekliyor →</span>}
              />
            </Link>
            <StatCard
              title="Süresi Dolacak"
              value={fmt.format(data.stats.expiring)}
              icon={CalendarClock}
              tone="warning"
              footer={<span className="text-warning">±{data.expiring_window_days} gün</span>}
            />
            {isAdmin ? (
              <StatCard title="Pasif / İptal" value={fmt.format(data.stats.inactive ?? 0)} icon={XCircle} tone="danger" footer={<span className="text-muted-foreground">İptal + süresi dolmuş</span>} />
            ) : (
              <StatCard title="Verdiğim Lisanslar" value={fmt.format(data.stats.created_by_me)} icon={KeyRound} footer={<span className="text-muted-foreground">Toplam</span>} />
            )}
          </div>
        )}

        <Card className="overflow-hidden">
          <CardHeader className="pb-3">
            <CardTitle>{isAdmin ? "Son Lisanslar" : "Verdiğim Lisanslar ve Kurduğum Demolar"}</CardTitle>
            {can("license.view") && (
              <Link to="/lisanslar?scope=expiring" className="text-xs font-medium text-accent-foreground hover:underline">
                Süresi dolacaklar →
              </Link>
            )}
          </CardHeader>
          {loading ? (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">Yükleniyor…</p>
          ) : (
            <LicenseTable rows={data?.recent ?? []} empty="Henüz lisans yok. İlk lisansı “Yeni Lisans” ile verebilirsiniz." />
          )}
        </Card>
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
        {isAdmin && data?.distribution && data.distribution.length > 0 && (
          <DistributionCard
            title="Ürün Dağılımı"
            slices={data.distribution.map((d, i) => ({ ...d, color: CHART_COLORS[i % CHART_COLORS.length] }))}
          />
        )}
      </div>
    </div>
  );
}
