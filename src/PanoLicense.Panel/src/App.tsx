import type { ReactNode } from "react";
import { Navigate, Route, Routes, useLocation } from "react-router-dom";
import { AppLayout } from "@/components/layout/AppLayout";
import { useAuth } from "@/lib/auth";
import { NAV_ITEMS } from "@/lib/navigation";
import { CustomersPage } from "@/pages/CustomersPage";
import { DashboardPage } from "@/pages/DashboardPage";
import { EmptyStatePage } from "@/pages/EmptyStatePage";
import { LicenseDetailPage } from "@/pages/LicenseDetailPage";
import { LicenseNewPage } from "@/pages/LicenseNewPage";
import { LicensesPage } from "@/pages/LicensesPage";
import { LoginPage } from "@/pages/LoginPage";
import { SettingsPage } from "@/pages/SettingsPage";

function FullScreenLoading() {
  return <div className="grid min-h-screen place-items-center text-sm text-muted-foreground">Yükleniyor…</div>;
}

/** Oturum yoksa giriş sayfasına yönlendirir. */
function RequireAuth({ children }: { children: ReactNode }) {
  const { me, loading } = useAuth();
  const location = useLocation();
  if (loading) return <FullScreenLoading />;
  if (!me) return <Navigate to="/giris" replace state={{ from: location.pathname }} />;
  return children;
}

/** Yetki yoksa Dashboard'a döner (yalnız görünürlük; API ayrıca reddeder). */
function RequireAbility({ ability, children }: { ability?: string; children: ReactNode }) {
  const { can } = useAuth();
  if (ability && !can(ability)) return <Navigate to="/" replace />;
  return children;
}

export default function App() {
  const { me, loading } = useAuth();
  const placeholders = NAV_ITEMS.filter((item) => item.phase);

  return (
    <Routes>
      <Route
        path="/giris"
        element={loading ? <FullScreenLoading /> : me ? <Navigate to="/" replace /> : <LoginPage />}
      />
      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="lisanslar" element={<RequireAbility ability="license.view"><LicensesPage /></RequireAbility>} />
        <Route path="lisanslar/yeni" element={<RequireAbility ability="license.create"><LicenseNewPage /></RequireAbility>} />
        <Route path="lisanslar/:id" element={<RequireAbility ability="license.view"><LicenseDetailPage /></RequireAbility>} />
        <Route path="musteriler" element={<RequireAbility ability="customer.view"><CustomersPage /></RequireAbility>} />
        <Route path="ayarlar" element={<RequireAbility ability="user.manage"><SettingsPage /></RequireAbility>} />
        {placeholders.map((item) => (
          <Route
            key={item.path}
            path={item.path.slice(1)}
            element={<RequireAbility ability={item.ability}><EmptyStatePage item={item} /></RequireAbility>}
          />
        ))}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}
