import { Search } from "lucide-react";
import { useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Alert, Input } from "@/components/ui/input";
import type { Customer, Paginated } from "@/lib/types";
import { useApi } from "@/lib/useApi";

export function CustomersPage() {
  const [input, setInput] = useState("");
  const [q, setQ] = useState("");
  const { data, error, loading } = useApi<Paginated<Customer>>(q ? `/customers?q=${encodeURIComponent(q)}` : "/customers");

  function submit(e: FormEvent) {
    e.preventDefault();
    setQ(input.trim());
  }

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Müşteriler</h1>
      <form onSubmit={submit} className="flex max-w-xl gap-2" role="search">
        <Input value={input} onChange={(e) => setInput(e.target.value)} placeholder="Firma adı" aria-label="Firma ara" />
        <Button type="submit" variant="outline">
          <Search /> Ara
        </Button>
      </form>
      {error && <Alert>{error}</Alert>}
      <Card className="overflow-hidden">
        {loading ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">Yükleniyor…</p>
        ) : rows.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">Firma yok. Firmalar yeni lisans verilirken oluşturulur.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th className="px-4 py-2.5 font-medium">Firma</th>
                  <th className="px-4 py-2.5 font-medium">Şehir</th>
                  <th className="px-4 py-2.5 font-medium">Telefon</th>
                  <th className="px-4 py-2.5 font-medium">Aktif lisans</th>
                  <th className="px-4 py-2.5 font-medium">Ekleyen</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((c) => (
                  <tr key={c.id} className="border-b last:border-0">
                    <td className="px-4 py-3 font-medium">
                      <Link to={`/lisanslar?q=${encodeURIComponent(c.name)}`} className="hover:underline">
                        {c.name}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{c.city ?? "—"}</td>
                    <td className="px-4 py-3 text-muted-foreground">{c.phone ?? "—"}</td>
                    <td className="px-4 py-3 tabular-nums">{c.active_license_count}</td>
                    <td className="px-4 py-3 text-muted-foreground">{c.created_by ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
