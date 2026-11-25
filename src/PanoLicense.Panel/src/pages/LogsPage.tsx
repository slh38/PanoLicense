import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";
import { Link } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Alert } from "@/components/ui/input";
import { actionLabel, actorName, describe, GROUPS, isWarning, type AuditEntry } from "@/lib/audit";
import { formatDateTime } from "@/lib/format";
import { useApi } from "@/lib/useApi";

interface LogsResponse {
  data: AuditEntry[];
  current_page: number;
  last_page: number;
  total: number;
  users: { id: number; name: string }[];
}

const selectClass = "h-9 rounded-lg border bg-card px-3 text-sm outline-none focus:border-primary";

export function LogsPage() {
  const [group, setGroup] = useState("");
  const [userId, setUserId] = useState("");
  const [page, setPage] = useState(1);

  const params = new URLSearchParams({ page: String(page) });
  if (group) params.set("group", group);
  if (userId) params.set("user_id", userId);
  const { data, error, loading } = useApi<LogsResponse>(`/audit-logs?${params}`);

  function filter(set: (v: string) => void, value: string) {
    set(value);
    setPage(1);
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-bold">Loglar</h1>
        <p className="text-sm text-muted-foreground">Giriş, lisans, firma ve kullanıcı işlemlerinin değiştirilemez kaydı.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        <select aria-label="İşlem türü" className={selectClass} value={group} onChange={(e) => filter(setGroup, e.target.value)}>
          {GROUPS.map((g) => (
            <option key={g.value} value={g.value}>
              {g.label}
            </option>
          ))}
        </select>
        <select aria-label="Kullanıcı" className={selectClass} value={userId} onChange={(e) => filter(setUserId, e.target.value)}>
          <option value="">Tüm kullanıcılar</option>
          {data?.users.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </div>

      {error && <Alert>{error}</Alert>}

      <Card className="overflow-hidden">
        {loading && !data ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">Yükleniyor…</p>
        ) : !data || data.data.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">Kayıt yok.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th className="px-4 py-2.5 font-medium">Zaman</th>
                  <th className="px-4 py-2.5 font-medium">Kullanıcı</th>
                  <th className="px-4 py-2.5 font-medium">İşlem</th>
                  <th className="px-4 py-2.5 font-medium">Kayıt</th>
                  <th className="px-4 py-2.5 font-medium">Ayrıntı</th>
                  <th className="px-4 py-2.5 font-medium">IP</th>
                </tr>
              </thead>
              <tbody className={loading ? "opacity-60" : ""}>
                {data.data.map((log) => (
                  <tr key={log.id} className="border-b align-top last:border-0">
                    <td className="whitespace-nowrap px-4 py-3 tabular-nums text-muted-foreground">{formatDateTime(log.created_at)}</td>
                    <td className="whitespace-nowrap px-4 py-3">{actorName(log)}</td>
                    <td className="whitespace-nowrap px-4 py-3">
                      <Badge variant={isWarning(log.action) ? "danger" : "default"}>{actionLabel(log.action)}</Badge>
                    </td>
                    <td className="whitespace-nowrap px-4 py-3">
                      {log.subject?.type === "License" ? (
                        <Link to={`/lisanslar/${log.subject.id}`} className="font-mono text-xs hover:underline">
                          {log.subject.label ?? `#${log.subject.id}`}
                        </Link>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{describe(log) || "—"}</td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-muted-foreground">{log.ip_address ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {data && data.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">
            Sayfa {data.current_page} / {data.last_page} · toplam {data.total} kayıt
          </span>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              <ChevronLeft /> Önceki
            </Button>
            <Button variant="outline" size="sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>
              Sonraki <ChevronRight />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
