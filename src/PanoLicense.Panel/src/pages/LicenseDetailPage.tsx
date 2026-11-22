import { ArrowLeft, Ban, CalendarPlus, Copy } from "lucide-react";
import { useState, type FormEvent, type ReactNode } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { actionLabel, describe, type AuditEntry } from "@/lib/audit";
import { useAuth } from "@/lib/auth";
import { formatDate, formatDateTime, STATE_LABEL, STATE_VARIANT } from "@/lib/format";
import type { License } from "@/lib/types";
import { useApi } from "@/lib/useApi";

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[140px_1fr] gap-3 border-b py-2.5 text-sm last:border-0">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words">{children}</dd>
    </div>
  );
}

export function LicenseDetailPage() {
  const { id } = useParams();
  const location = useLocation();
  const { can } = useAuth();
  const { data, error, loading, setData } = useApi<{ data: License }>(`/licenses/${id}`);
  const [notice, setNotice] = useState<string | null>((location.state as { created?: boolean } | null)?.created ? "Lisans oluşturuldu." : null);
  const [copied, setCopied] = useState(false);

  const license = data?.data;
  const isActive = license?.status === "active";

  async function copyKey() {
    if (!license) return;
    try {
      await navigator.clipboard.writeText(license.license_key);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      /* pano erişimi yoksa sessizce geç */
    }
  }

  if (loading) return <p className="text-sm text-muted-foreground">Yükleniyor…</p>;
  if (error || !license) return <Alert>{error ?? "Lisans bulunamadı."}</Alert>;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to="/lisanslar" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> Lisanslar
      </Link>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-xl font-bold">{license.customer.name}</h1>
        <Badge variant={STATE_VARIANT[license.state]}>{STATE_LABEL[license.state]}</Badge>
      </div>

      {notice && <Alert kind="success">{notice}</Alert>}

      <Card>
        <CardContent className="py-2">
          <dl>
            <Row label="Lisans kodu">
              <span className="inline-flex items-center gap-2">
                <span className="font-mono">{license.license_key}</span>
                <button type="button" onClick={copyKey} className="text-muted-foreground hover:text-foreground" aria-label="Lisans kodunu kopyala">
                  <Copy className="size-4" />
                </button>
                {copied && <span className="text-xs text-success">Kopyalandı</span>}
              </span>
            </Row>
            <Row label="Ürün">{license.product.name}</Row>
            <Row label="Firma">
              {license.customer.name}
              {license.customer.city && <span className="text-muted-foreground"> · {license.customer.city}</span>}
            </Row>
            <Row label="Cihaz limiti">{license.device_limit}</Row>
            <Row label="Geçerlilik">
              {formatDate(license.starts_at)} – {formatDate(license.expires_at)}
            </Row>
            <Row label="Lisansı veren">
              <b>{license.created_by.name}</b>
              <span className="text-muted-foreground"> · {formatDateTime(license.created_at)}</span>
            </Row>
            {license.notes && <Row label="Not">{license.notes}</Row>}
            {license.status === "revoked" && (
              <>
                <Row label="İptal eden">
                  {license.revoked_by?.name ?? "—"} <span className="text-muted-foreground">· {formatDateTime(license.revoked_at)}</span>
                </Row>
                <Row label="İptal nedeni">{license.revoke_reason}</Row>
              </>
            )}
          </dl>
        </CardContent>
      </Card>

      {can("audit.view") && <LicenseHistory licenseId={license.id} version={license.expires_at + license.status} />}

      {isActive && (can("license.extend") || can("license.revoke")) && (
        <div className="grid gap-4 md:grid-cols-2">
          {can("license.extend") && (
            <ExtendCard license={license} onDone={(l) => { setData({ data: l }); setNotice("Süre uzatıldı."); }} />
          )}
          {can("license.revoke") && (
            <RevokeCard license={license} onDone={(l) => { setData({ data: l }); setNotice("Lisans iptal edildi."); }} />
          )}
        </div>
      )}
    </div>
  );
}

/** Bu lisansa ait denetim kayıtları: kim verdi, kim uzattı, kim iptal etti. */
function LicenseHistory({ licenseId, version }: { licenseId: number; version: string }) {
  const { data } = useApi<{ data: AuditEntry[] }>(`/audit-logs?license_id=${licenseId}&v=${encodeURIComponent(version)}`);
  if (!data || data.data.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Geçmiş</CardTitle>
      </CardHeader>
      <CardContent className="pt-2">
        <ul className="space-y-2 text-sm">
          {data.data.map((log) => (
            <li key={log.id} className="flex flex-wrap gap-x-2">
              <span className="tabular-nums text-muted-foreground">{formatDateTime(log.created_at)}</span>
              <b>{log.user?.name ?? "—"}</b>
              <span>{actionLabel(log.action).toLocaleLowerCase("tr-TR")}</span>
              {describe(log) && <span className="text-muted-foreground">· {describe(log)}</span>}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function ExtendCard({ license, onDone }: { license: License; onDone: (l: License) => void }) {
  const [date, setDate] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/extend`, { method: "POST", body: { expires_at: date } });
      onDone(res.data);
      setDate("");
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Süre uzat</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-3">
          {error && <Alert>{error}</Alert>}
          <Field label="Yeni bitiş tarihi" hint={`Şu anki: ${formatDate(license.expires_at)}`}>
            <Input type="date" min={license.expires_at} value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          <Button type="submit" variant="outline" disabled={busy || !date}>
            <CalendarPlus /> Süreyi uzat
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function RevokeCard({ license, onDone }: { license: License; onDone: (l: License) => void }) {
  const [reason, setReason] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!confirming) {
      setConfirming(true);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/revoke`, { method: "POST", body: { reason } });
      onDone(res.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
      setConfirming(false);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Lisansı iptal et</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-3">
          {error && <Alert>{error}</Alert>}
          <Field label="İptal nedeni" hint="Kayıt silinmez; iptal geçmişiyle saklanır.">
            <Input value={reason} onChange={(e) => { setReason(e.target.value); setConfirming(false); }} maxLength={500} />
          </Field>
          <Button
            type="submit"
            variant="outline"
            disabled={busy || reason.trim().length < 3}
            className={confirming ? "border-danger text-danger hover:bg-danger-soft hover:text-danger" : ""}
          >
            <Ban /> {confirming ? "Emin misiniz? Tekrar tıklayın" : "İptal et"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
