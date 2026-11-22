import { Navigate, Route, Routes } from "react-router-dom";
import { AppLayout } from "@/components/layout/AppLayout";
import { NAV_ITEMS } from "@/lib/navigation";
import { DashboardPage } from "@/pages/DashboardPage";
import { EmptyStatePage } from "@/pages/EmptyStatePage";

export default function App() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<DashboardPage />} />
        {NAV_ITEMS.filter((item) => item.path !== "/").map((item) => (
          <Route key={item.path} path={item.path} element={<EmptyStatePage item={item} />} />
        ))}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}
