import { ArrowLeft, Ban, CalendarPlus, Check, CheckCircle2, CloudOff, Copy, Infinity as InfinityIcon, Package as PackageIcon, Puzzle, Save, Unplug } from "lucide-react";
import { useState, type FormEvent, type ReactNode } from "react";
import { Link, useLocation, useParams } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input, Textarea } from "@/components/ui/input";
import { OfflinePolicyPicker, OfflineRevokeWarning } from "@/components/licenses/OfflinePolicy";
import { LicenseTypePicker, PerpetualRolloutNote } from "@/components/licenses/LicenseTypePicker";
import { PackagePicker } from "@/components/licenses/PackagePicker";
import { api, ApiError } from "@/lib/api";
import {
  canChangeOfflinePolicy,
  EXTENDED_OFFLINE_DAYS,
  normalizeOfflineDays,
  OFFLINE_POLICY_ABILITY,
  offlinePolicyBody,
  offlinePolicyLabel,
  STANDARD_OFFLINE_DAYS,
  type OfflineGraceDays,
} from "@/lib/offline";
import { actionLabel, actorName, describe, type AuditEntry } from "@/lib/audit";
import { useAuth } from "@/lib/auth";
import {
  canConvertToPerpetual,
  canExtend,
  isPerpetual,
  licenseTypeBody,
  licenseTypeErrors,
  licenseTypeLabel,
  PERPETUAL_ABILITY,
  type LicenseType,
} from "@/lib/licenseType";
import { formatDate, formatDateTime, installerLabel, isoDatePlus, STATE_LABEL, STATE_VARIANT } from "@/lib/format";
import type { CatalogProduct, License, Product } from "@/lib/types";
import { useApi } from "@/lib/useApi";
import { cn } from "@/lib/utils";

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
            <Row label="Paket">
              {license.package ? <b>{license.package.name}</b> : <span className="text-muted-foreground">Paket yok</span>}
            </Row>
            <Row label="Modüller">
              <ModuleChips modules={license.modules ?? []} />
            </Row>
            <Row label="Firma">
              {license.customer.name}
              {license.customer.city && <span className="text-muted-foreground"> · {license.customer.city}</span>}
            </Row>
            {license.customer.phone && <Row label="Telefon">{license.customer.phone}</Row>}
            <Row label="Cihaz">
              {license.active_device_count ?? 0} / {license.device_limit} aktif
            </Row>
            <Row label="Lisans türü">
              <Badge variant={isPerpetual(license) ? "success" : "outline"}>
                {isPerpetual(license) && <InfinityIcon />} {licenseTypeLabel(license)}
              </Badge>
            </Row>
            <Row label={license.status === "demo" ? "Demo süresi" : "Geçerlilik"}>
              {isPerpetual(license)
                ? `${formatDate(license.starts_at)} tarihinden itibaren · bitiş yok`
                : `${formatDate(license.starts_at)} – ${formatDate(license.expires_at)}`}
            </Row>
            <Row label="Offline çalışma">
              <span className="inline-flex flex-wrap items-center gap-2">
                <Badge variant={normalizeOfflineDays(license.offline_grace_days) === EXTENDED_OFFLINE_DAYS ? "warning" : "default"}>
                  <CloudOff /> {offlinePolicyLabel(license.offline_grace_days)}
                </Badge>
                {license.status === "demo" && <span className="text-xs text-muted-foreground">Demo lisanslarda daima 7 gün</span>}
              </span>
              {license.status === "active" && normalizeOfflineDays(license.offline_grace_days) === EXTENDED_OFFLINE_DAYS &&
                !canChangeOfflinePolicy(license, can) && <div className="mt-2"><OfflineRevokeWarning /></div>}
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

      {canChangeOfflinePolicy(license, can) && (
        <OfflinePolicyCard
          license={license}
          onDone={(l, changed) => {
            setData({ data: l });
            setNotice(changed ? "Offline politika güncellendi. Cihazlar yeni politikayı bir sonraki online kontrolde alır." : "Politika zaten bu değerde.");
          }}
        />
      )}

      {license.status !== "revoked" && can("license.modules") && (
        <PackageModulesCard
          license={license}
          onDone={(l, text) => { setData({ data: l }); setNotice(text); }}
        />
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
          version={`${license.license_type}${license.expires_at}${license.status}${license.active_device_count ?? 0}${license.offline_grace_days}${license.package?.id}${(license.addon_module_ids ?? []).join()}`}
        />
      )}

      {canConvertToPerpetual(license, can) && (
        <ConvertPerpetualCard
          license={license}
          onDone={(l) => { setData({ data: l }); setNotice("Lisans ömür boyuna dönüştürüldü. Cihazlar yeni türü bir sonraki online kontrolde alır."); }}
        />
      )}

      {license.status !== "revoked" && (canExtend(license, can) || can("license.revoke")) && (
        <div className="grid gap-4 md:grid-cols-2">
          {canExtend(license, can) && (
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
  const { can } = useAuth();
  const canChooseExtended = can(OFFLINE_POLICY_ABILITY);
  const canPerpetual = can(PERPETUAL_ABILITY);
  const [deviceLimit, setDeviceLimit] = useState(1);
  const [startsAt, setStartsAt] = useState(isoDatePlus(0));
  const [licenseType, setLicenseType] = useState<LicenseType>("term");
  const [expiresAt, setExpiresAt] = useState(isoDatePlus(365));
  const [notes, setNotes] = useState("");
  const [offlineDays, setOfflineDays] = useState<OfflineGraceDays>(STANDARD_OFFLINE_DAYS);
  // Demo Pro paketiyle gelir; onaylayan satılan paketi seçer.
  const products = useApi<Product[]>("/products");
  const packages = products.data?.find((p) => p.id === license.product.id)?.packages ?? [];
  const [packageId, setPackageId] = useState<number | "">(license.package?.id ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const err = (k: string) => errors[k]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setMessage(null);
    const typeErrors = licenseTypeErrors(licenseType, startsAt, expiresAt, canPerpetual);
    setErrors(typeErrors);
    if (Object.keys(typeErrors).length) return;
    setBusy(true);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/approve`, {
        method: "POST",
        body: {
          customer: Object.fromEntries(Object.entries(customer).map(([k, v]) => [k, v.trim() || null])),
          device_limit: deviceLimit,
          starts_at: startsAt,
          ...licenseTypeBody(licenseType, expiresAt),
          notes: notes || null,
          ...offlinePolicyBody(offlineDays, canChooseExtended),
          ...(packageId === "" ? {} : { package_id: packageId }),
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
          <PackagePicker
            packages={packages}
            value={packageId}
            onChange={(pkg) => { setPackageId(pkg.id); setDeviceLimit(pkg.default_device_limit); }}
            error={err("package_id")}
          />
          <Field label="Cihaz limiti *" error={err("device_limit")} hint="Firmanın satın aldığı cihaz sayısı">
            <Input type="number" min={1} max={1000} value={deviceLimit} onChange={(e) => setDeviceLimit(Number(e.target.value))} />
          </Field>
          <Field label="Başlangıç *" error={err("starts_at")}>
            <Input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} />
          </Field>
          <div className="sm:col-span-2">
            <LicenseTypePicker value={licenseType} onChange={setLicenseType} canPerpetual={canPerpetual} error={err("license_type")} />
          </div>
          {licenseType === "term" && (
            <Field label="Bitiş *" error={err("expires_at")}>
              <Input type="date" min={startsAt} value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
            </Field>
          )}
          <div className="sm:col-span-2">
            <OfflinePolicyPicker
              value={offlineDays}
              onChange={setOfflineDays}
              canChooseExtended={canChooseExtended}
              error={err("offline_grace_days")}
            />
            <p className="mt-1.5 text-xs text-muted-foreground">Onay öncesi demo 7 gündür; seçim onayla birlikte ticari lisansa uygulanır.</p>
          </div>
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

/** Geçerli modüller: paketten gelenler ve lisansa özel ek modüller ("ek" rozeti). */
function ModuleChips({ modules }: { modules: NonNullable<License["modules"]> }) {
  if (modules.length === 0) return <span className="text-muted-foreground">Modül yok</span>;
  return (
    <span className="flex flex-wrap gap-1.5">
      {modules.map((m) => (
        <Badge key={m.id} variant={m.source === "license" ? "default" : "outline"} title={m.code}>
          {m.name}
          {m.source === "license" && <span className="font-semibold">· ek</span>}
        </Badge>
      ))}
    </span>
  );
}

/**
 * Paket değiştirme ve lisansa özel ek modüller (yalnız Admin). Paket değişince cihaz limiti ve ek modüller değişmez.
 * Cihazlar yeni modül listesini sonraki başarılı online kontrolde alır.
 */
function PackageModulesCard({ license, onDone }: { license: License; onDone: (l: License, notice: string) => void }) {
  const catalog = useApi<{ data: CatalogProduct[] }>("/catalog");
  const product = catalog.data?.data.find((p) => p.id === license.product.id);
  const [packageId, setPackageId] = useState<number | "">(license.package?.id ?? "");
  const [addons, setAddons] = useState<number[]>(license.addon_module_ids ?? []);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const selectedPackage = product?.packages.find((p) => p.id === packageId);
  const packageModuleIds = selectedPackage?.module_ids ?? [];
  const addonsDirty = [...addons].sort().join() !== [...(license.addon_module_ids ?? [])].sort().join();
  const packageDirty = packageId !== "" && packageId !== license.package?.id;
  const note = "Cihazlar değişikliği sonraki online kontrolde alır.";

  async function call(path: string, method: "POST" | "PUT", body: unknown, text: string) {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: License }>(path, { method, body });
      onDone(res.data, text);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Paket ve modüller</CardTitle>
        <span className="text-xs text-muted-foreground">Yalnız Admin · loglanır</span>
      </CardHeader>
      <CardContent className="space-y-4">
        {error && <Alert>{error}</Alert>}
        {!product ? (
          <p className="text-sm text-muted-foreground">{catalog.error ?? "Yükleniyor…"}</p>
        ) : (
          <>
            <div className="flex flex-wrap items-end gap-3">
              <div className="min-w-48 flex-1">
                <Field label="Paket" hint="Cihaz limiti ve ek modüller değişmez.">
                  <select
                    value={packageId}
                    disabled={busy}
                    onChange={(e) => setPackageId(Number(e.target.value))}
                    className="h-9 w-full rounded-lg border bg-card px-3 text-sm outline-none focus:border-primary"
                  >
                    {packageId === "" && <option value="">Paket seçin</option>}
                    {product.packages.filter((p) => p.is_active || p.id === license.package?.id).map((p) => (
                      <option key={p.id} value={p.id}>{p.name}{p.is_active ? "" : " (pasif)"}</option>
                    ))}
                  </select>
                </Field>
              </div>
              <Button variant="outline" disabled={busy || !packageDirty}
                onClick={() => call(`/licenses/${license.id}/package`, "POST", { package_id: packageId }, `Paket değiştirildi. ${note}`)}>
                <PackageIcon /> Paketi değiştir
              </Button>
            </div>

            <div className="space-y-2">
              <div className="text-xs font-medium text-muted-foreground">Lisansa özel ek modüller</div>
              {product.modules.length === 0 ? (
                <p className="text-xs text-muted-foreground">Bu ürüne modül tanımlı değil (Ürünler sayfası).</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {product.modules.map((m) => {
                    const inPackage = packageModuleIds.includes(m.id);
                    const on = addons.includes(m.id);
                    return (
                      <button
                        key={m.id}
                        type="button"
                        disabled={busy || !m.is_active}
                        aria-pressed={on}
                        title={inPackage ? "Pakette zaten var" : m.code}
                        onClick={() => setAddons((a) => (a.includes(m.id) ? a.filter((id) => id !== m.id) : [...a, m.id]))}
                        className={cn(
                          "inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50",
                          on ? "border-primary bg-accent text-accent-foreground" : "bg-card text-muted-foreground hover:bg-muted/60",
                        )}
                      >
                        {on ? <Check className="size-3.5" /> : <Puzzle className="size-3.5" />}
                        {m.name}
                        {inPackage && <span className="opacity-70">(pakette)</span>}
                      </button>
                    );
                  })}
                </div>
              )}
              {addonsDirty && (
                <Button size="sm" disabled={busy}
                  onClick={() => call(`/licenses/${license.id}/modules`, "PUT", { module_ids: addons }, `Ek modüller güncellendi. ${note}`)}>
                  <Save /> Ek modülleri kaydet
                </Button>
              )}
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** Offline politika değişikliği (yalnız Admin, yalnız aktif ticari lisans). */
function OfflinePolicyCard({ license, onDone }: { license: License; onDone: (l: License, changed: boolean) => void }) {
  const current = normalizeOfflineDays(license.offline_grace_days);
  const [value, setValue] = useState<OfflineGraceDays>(current);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const dirty = value !== current;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/offline-policy`, {
        method: "POST",
        body: { offline_grace_days: value },
      });
      onDone(res.data, dirty);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Offline çalışma politikası</CardTitle>
        <span className="text-xs text-muted-foreground">Şu an: {offlinePolicyLabel(current)}</span>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-3">
          {error && <Alert>{error}</Alert>}
          <OfflinePolicyPicker value={value} onChange={setValue} canChooseExtended disabled={busy} />
          {dirty && (
            <p className="text-xs text-muted-foreground">
              Değişiklik cihazlara bir sonraki online kontrolde ulaşır; daha önce verilmiş offline izinler geriye dönük kısalmaz.
            </p>
          )}
          <Button type="submit" variant="outline" disabled={busy || !dirty}>
            <CloudOff /> {busy ? "Kaydediliyor…" : "Politikayı kaydet"}
          </Button>
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

/**
 * Süreli → ömür boyu tek yönlü dönüşüm (yalnız Admin, yalnız aktif süreli lisans).
 * Onay ekranı korunan bilgileri ve geri alınamazlığı açıkça yazar.
 */
function ConvertPerpetualCard({ license, onDone }: { license: License; onDone: (l: License) => void }) {
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const deviceCount = license.active_device_count ?? 0;
  const moduleCount = license.modules?.length ?? 0;

  async function convert() {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ data: License }>(`/licenses/${license.id}/convert-perpetual`, {
        method: "POST",
        body: { license_type: "perpetual" },
      });
      onDone(res.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage() : "Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
      setConfirming(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Ömür boyu lisansa dönüştür</CardTitle>
        <span className="text-xs text-muted-foreground">Yalnız Admin · loglanır</span>
      </CardHeader>
      <CardContent className="space-y-3">
        {error && <Alert>{error}</Alert>}
        {!confirming ? (
          <>
            <p className="text-sm text-muted-foreground">
              Süreli lisansın bitiş tarihi ({formatDate(license.expires_at)}) kaldırılır ve lisans ömür boyu olur.
            </p>
            <Button variant="outline" disabled={busy} onClick={() => setConfirming(true)}>
              <InfinityIcon /> Ömür boyuna dönüştür
            </Button>
          </>
        ) : (
          <div role="alertdialog" aria-label="Ömür boyu dönüşüm onayı" className="space-y-3 rounded-lg border border-primary/40 p-3">
            <p className="text-sm font-medium">
              {license.customer.name} lisansı ömür boyuna dönüştürülecek. Emin misiniz?
            </p>
            <ul className="list-disc space-y-1 pl-5 text-sm">
              <li>Lisans anahtarı aynı kalır: <span className="font-mono">{license.license_key}</span></li>
              <li>Bağlı cihazlar ({deviceCount}/{license.device_limit}) ve cihaz limiti korunur.</li>
              <li>Paket ({license.package?.name ?? "paket yok"}) ve {moduleCount} modül (ek modüller dahil) korunur.</li>
              <li>Offline çalışma politikası ({offlinePolicyLabel(license.offline_grace_days)}) değişmez; ömür boyu sınırsız offline değildir.</li>
              <li>
                <b>Geri alınamaz:</b> lisans tekrar süreliye çevrilemez ve süre uzatma kalkar. Bakım/güncelleme hakkı ayrıdır.
              </li>
            </ul>
            <PerpetualRolloutNote />
            <div className="flex flex-wrap gap-2">
              <Button disabled={busy} onClick={convert}>
                <InfinityIcon /> {busy ? "Dönüştürülüyor…" : "Evet, ömür boyuna dönüştür"}
              </Button>
              <Button variant="outline" disabled={busy} onClick={() => setConfirming(false)}>
                Vazgeç
              </Button>
            </div>
          </div>
        )}
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
            <Input type="date" min={license.expires_at ?? undefined} value={date} onChange={(e) => setDate(e.target.value)} />
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
