import { Plus, Search } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { LicenseTable } from "@/components/licenses/LicenseTable";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Alert, Input } from "@/components/ui/input";
import { useAuth } from "@/lib/auth";
import type { License, Paginated } from "@/lib/types";
import { useApi } from "@/lib/useApi";
import { cn } from "@/lib/utils";

type Tab = "expiring" | "demo" | "all";

const EMPTY: Record<Tab, string> = {
  expiring: "Önümüzdeki 30 gün içinde süresi dolacak lisans yok.",
  demo: "Onay bekleyen demo yok. PanoPOS'ta demo kurulunca burada görünür.",
  all: "Henüz lisans yok.",
};

export function LicensesPage() {
  const { can } = useAuth();
  const canListAll = can("license.list_all");
  const [params, setParams] = useSearchParams();
  const q = params.get("q")?.trim() ?? "";
  const scopeParam = params.get("scope");
  const tab: Tab = q
    ? "all"
    : scopeParam === "demo"
      ? "demo"
      : scopeParam === "all" && canListAll
        ? "all"
        : "expiring";
  const [input, setInput] = useState(q);

  useEffect(() => setInput(q), [q]);

  // User: arama, süresi dolacaklar ve demo talepleri. Admin: ayrıca filtresiz tüm liste.
  const path = q.length >= 2 ? `/licenses?q=${encodeURIComponent(q)}` : `/licenses?scope=${tab}`;
  const { data, error, loading } = useApi<Paginated<License>>(path);

  function submit(e: FormEvent) {
    e.preventDefault();
    const term = input.trim();
    setParams(term.length >= 2 ? { q: term } : {});
  }

  const tabs: { id: Tab; label: string; show: boolean }[] = [
    { id: "expiring", label: "Süresi Dolacak (30 gün)", show: true },
    { id: "demo", label: "Demo Talepleri", show: true },
    { id: "all", label: "Tüm Lisanslar", show: canListAll },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-bold">Lisanslar</h1>
        {can("license.create") && (
          <Button asChild>
            <Link to="/lisanslar/yeni">
              <Plus /> Yeni Lisans
            </Link>
          </Button>
        )}
      </div>

      <form onSubmit={submit} className="flex max-w-xl gap-2" role="search">
        <Input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="Firma adı veya lisans kodu (en az 2 karakter)"
          aria-label="Lisans ara"
        />
        <Button type="submit" variant="outline">
          <Search /> Ara
        </Button>
      </form>

      <Card className="overflow-hidden">
        <div role="tablist" className="flex gap-1 overflow-x-auto overflow-y-hidden border-b px-3 pt-3 [scrollbar-width:none]">
          {q ? (
            <span className="whitespace-nowrap border-b-2 border-primary px-3 pb-2.5 pt-2 text-sm font-medium">
              “{q}” arama sonuçları
            </span>
          ) : (
            tabs.filter((t) => t.show).map((t) => (
              <button
                key={t.id}
                role="tab"
                type="button"
                aria-selected={tab === t.id}
                onClick={() => setParams(t.id === "expiring" ? {} : { scope: t.id })}
                className={cn(
                  "whitespace-nowrap border-b-2 px-3 pb-2.5 pt-2 text-sm font-medium transition-colors",
                  tab === t.id ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground",
                )}
              >
                {t.label}
              </button>
            ))
          )}
          {q && (
            <button type="button" onClick={() => setParams({})} className="ml-auto pb-2.5 text-xs text-muted-foreground hover:text-foreground">
              Aramayı temizle
            </button>
          )}
        </div>
        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {loading ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">Yükleniyor…</p>
        ) : (
          <LicenseTable
            rows={data?.data ?? []}
            empty={q ? "Aramaya uyan lisans bulunamadı." : EMPTY[tab]}
          />
        )}
      </Card>
      {data?.meta && data.meta.total > data.data.length && (
        <p className="text-xs text-muted-foreground">
          İlk {data.data.length} kayıt gösteriliyor (toplam {data.meta.total}). Aramayı daraltın.
        </p>
      )}
    </div>
  );
}
