import { ArrowLeft, Ban, CalendarPlus, CheckCircle2, Copy, Unplug } from "lucide-react";
import { useState, type FormEvent, type ReactNode } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input, Textarea } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { actionLabel, actorName, describe, type AuditEntry } from "@/lib/audit";
import { useAuth } from "@/lib/auth";
import { formatDate, formatDateTime, installerLabel, isoDatePlus, STATE_LABEL, STATE_VARIANT } from "@/lib/format";
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
            {license.customer.phone && <Row label="Telefon">{license.customer.phone}</Row>}
            <Row label="Cihaz">
              {license.active_device_count ?? 0} / {license.device_limit} aktif
            </Row>
            <Row label={license.status === "demo" ? "Demo süresi" : "Geçerlilik"}>
              {formatDate(license.starts_at)} – {formatDate(license.expires_at)}
            </Row>
            {license.source === "demo" && (
              <Row label="Kurulumu yapan">
                <b>{installerLabel(license.installer)}</b>
                <span className="text-muted-foreground"> · PanoPOS demo · {formatDateTime(license.created_at)}</span>
              </Row>
            )}
            <Row label="Lisansı veren">
              {license.created_by ? (
                <>
                  <b>{license.created_by.name}</b>
                  <span className="text-muted-foreground">
                    {" "}· {formatDateTime(license.approved_at ?? license.created_at)}
                    {license.source === "demo" && " (demo onayı)"}
                  </span>
                </>
              ) : (
                <span className="text-muted-foreground">Henüz onaylanmadı</span>
              )}
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

      {license.status === "demo" && can("license.create") && (
        <ApproveCard license={license} onDone={(l) => { setData({ data: l }); setNotice("Demo onaylandı; lisans aktif. PanoPOS bir sonraki kontrolde aktif lisansı görür."); }} />
      )}

      {license.devices && (
        <DevicesCard
          license={license}
          canRelease={can("device.release") && license.status !== "revoked"}
          onDone={(l) => { setData({ data: l }); setNotice("Cihaz serbest bırakıldı."); }}
        />
      )}

      {can("audit.view") && (
        <LicenseHistory
          licenseId={license.id}
          version={`${license.expires_at}${license.status}${license.active_device_count ?? 0}`}
        />
      )}

      {license.status !== "revoked" && ((isActive && can("license.extend")) || can("license.revoke")) && (
        <div className="grid gap-4 md:grid-cols-2">
          {isActive && can("license.extend") && (
            <ExtendCard license={license} onDone={(l) => { setData({ data: l }); setNotice("Süre uzatıldı."); }} />
          )}
          {can("license.revoke") && (
            <RevokeCard license={license} onDone={(l) => { setData({ data: l }); setNotice(l.source === "demo" && !l.approved_at ? "Demo reddedildi (iptal)." : "Lisans iptal edildi."); }} />
          )}
        </div>
      )}
    </div>
  );
}

/** Demo onayı: eksik firma bilgilerini tamamla, cihaz limiti ve süre ver, lisansı aktif et. */
function ApproveCard({ license, onDone }: { license: License; onDone: (l: License) => void }) {
  const [customer, setCustomer] = useState({
    name: license.customer.name,
    city: license.customer.city ?? "",
    phone: license.customer.phone ?? "",
    email: license.customer.email ?? "",
    tax_number: license.customer.tax_number ?? "",
  });
  const [deviceLimit, setDeviceLimit] = useState(1);
  const [startsAt, setStartsAt] = useState(isoDatePlus(0));
  const [expiresAt, setExpiresAt] = useState(isoDatePlus(365));
  const [notes, setNotes] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const err = (k: string) => errors[k]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/approve`, {
        method: "POST",
        body: {
          customer: Object.fromEntries(Object.entries(customer).map(([k, v]) => [k, v.trim() || null])),
          device_limit: deviceLimit,
          starts_at: startsAt,
          expires_at: expiresAt,
          notes: notes || null,
        },
      });
      onDone(res.data);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors);
        setMessage(e.firstMessage());
      } else setMessage("Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="border-primary/40">
      <CardHeader>
        <CardTitle>Demoyu onayla ve lisansı aktif et</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
          {message && <div className="sm:col-span-2"><Alert>{message}</Alert></div>}
          <div className="sm:col-span-2">
            <Field label="Firma adı *" error={err("customer.name")}>
              <Input value={customer.name} onChange={(e) => setCustomer({ ...customer, name: e.target.value })} maxLength={150} />
            </Field>
          </div>
          <Field label="Şehir / İlçe" error={err("customer.city")}>
            <Input value={customer.city} onChange={(e) => setCustomer({ ...customer, city: e.target.value })} maxLength={100} />
          </Field>
          <Field label="Telefon" error={err("customer.phone")}>
            <Input value={customer.phone} onChange={(e) => setCustomer({ ...customer, phone: e.target.value })} maxLength={30} />
          </Field>
          <Field label="E-posta" error={err("customer.email")}>
            <Input type="email" value={customer.email} onChange={(e) => setCustomer({ ...customer, email: e.target.value })} maxLength={150} />
          </Field>
          <Field label="Vergi no" error={err("customer.tax_number")}>
            <Input value={customer.tax_number} onChange={(e) => setCustomer({ ...customer, tax_number: e.target.value })} maxLength={20} />
          </Field>
          <Field label="Cihaz limiti *" error={err("device_limit")} hint="Firmanın satın aldığı cihaz sayısı">
            <Input type="number" min={1} max={1000} value={deviceLimit} onChange={(e) => setDeviceLimit(Number(e.target.value))} />
          </Field>
          <div />
          <Field label="Başlangıç *" error={err("starts_at")}>
            <Input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} />
          </Field>
          <Field label="Bitiş *" error={err("expires_at")}>
            <Input type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
          </Field>
          <div className="sm:col-span-2">
            <Field label="Not" error={err("notes")}>
              <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} />
            </Field>
          </div>
          <div className="sm:col-span-2">
            <Button type="submit" disabled={busy}>
              <CheckCircle2 /> {busy ? "Onaylanıyor…" : "Onayla ve aktif et"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

/** Lisansa bağlı cihazlar. Serbest bırakılan cihaz silinmez; yer açılır. */
function DevicesCard({ license, canRelease, onDone }: { license: License; canRelease: boolean; onDone: (l: License) => void }) {
  const [busyId, setBusyId] = useState<number | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const devices = license.devices ?? [];

  async function release(deviceId: number) {
    if (confirmId !== deviceId) {
      setConfirmId(deviceId);
      return;
    }
    setBusyId(deviceId);
    setError(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/devices/${deviceId}/release`, { method: "POST" });
      onDone(res.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
    } finally {
      setBusyId(null);
      setConfirmId(null);
    }
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader className="pb-3">
        <CardTitle>
          Cihazlar <span className="font-normal text-muted-foreground">({license.active_device_count ?? 0}/{license.device_limit})</span>
        </CardTitle>
      </CardHeader>
      {error && <div className="px-4 pb-3"><Alert>{error}</Alert></div>}
      {devices.length === 0 ? (
        <p className="px-4 pb-5 text-sm text-muted-foreground">Henüz cihaz aktive edilmedi.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[620px] text-sm">
            <thead>
              <tr className="border-y text-left text-xs text-muted-foreground">
                <th className="px-4 py-2 font-medium">Cihaz</th>
                <th className="px-4 py-2 font-medium">Sürüm</th>
                <th className="px-4 py-2 font-medium">İlk / son görülme</th>
                <th className="px-4 py-2 font-medium">Durum</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {devices.map((d) => (
                <tr key={d.id} className="border-b last:border-0">
                  <td className="px-4 py-2.5">
                    <div className="font-medium">{d.name ?? "Adsız cihaz"}</div>
                    <div className="font-mono text-xs text-muted-foreground">{d.fingerprint_short}…</div>
                  </td>
                  <td className="px-4 py-2.5 text-muted-foreground">{d.app_version ?? "—"}</td>
                  <td className="whitespace-nowrap px-4 py-2.5 text-xs text-muted-foreground">
                    {formatDateTime(d.first_seen_at)}
                    <br />
                    {formatDateTime(d.last_seen_at)}
                  </td>
                  <td className="px-4 py-2.5">
                    <Badge variant={d.status === "active" ? "success" : "outline"}>{d.status === "active" ? "Aktif" : "Serbest"}</Badge>
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    {canRelease && d.status === "active" && (
                      <Button size="sm" variant="outline" disabled={busyId === d.id} onClick={() => release(d.id)}
                        className={confirmId === d.id ? "border-danger text-danger" : ""}>
                        <Unplug /> {confirmId === d.id ? "Emin misiniz?" : "Serbest bırak"}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
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
              <b>{actorName(log)}</b>
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
