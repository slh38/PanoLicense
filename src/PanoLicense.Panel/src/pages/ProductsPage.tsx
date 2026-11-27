import { Boxes, Check, Package, Plus, Puzzle, Save } from "lucide-react";
import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import type { CatalogModule, CatalogPackage, CatalogProduct } from "@/lib/types";
import { useApi } from "@/lib/useApi";
import { cn } from "@/lib/utils";

type Tab = "packages" | "modules";

/** Kaydetme yardımcıları: hata mesajı + alan hataları. Başarıda katalog yeniden yüklenir. */
function useSubmit(onDone: () => void) {
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(call: () => Promise<unknown>, after?: () => void) {
    setBusy(true);
    setErrors({});
    setMessage(null);
    try {
      await call();
      after?.();
      onDone();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors);
        setMessage(e.firstMessage());
      } else setMessage("Sunucuya ulaşılamadı.");
    } finally {
      setBusy(false);
    }
  }

  return { run, busy, message, err: (k: string) => errors[k]?.[0] };
}

export function ProductsPage() {
  const { data, error, loading, reload } = useApi<{ data: CatalogProduct[] }>("/catalog");
  const products = data?.data ?? [];
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const selected = products.find((p) => p.id === selectedId) ?? products[0] ?? null;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold">Ürünler</h1>
          <p className="text-sm text-muted-foreground">Yazılımlar, paketleri ve modülleri. Kodlar imzalı lisansa girer ve sonradan değişmez.</p>
        </div>
        <Button onClick={() => setCreating((v) => !v)} variant={creating ? "outline" : "default"}>
          <Plus /> Yeni ürün
        </Button>
      </div>

      {error && <Alert>{error}</Alert>}
      {creating && (
        <NewProductCard
          onDone={(id) => {
            setCreating(false);
            setSelectedId(id);
            reload();
          }}
        />
      )}

      {loading && !data ? (
        <p className="text-sm text-muted-foreground">Yükleniyor…</p>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
          <Card className="h-fit overflow-hidden">
            <ul className="divide-y">
              {products.map((p) => (
                <li key={p.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedId(p.id)}
                    className={cn(
                      "flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/60",
                      selected?.id === p.id && "bg-accent hover:bg-accent",
                    )}
                  >
                    <div className="grid size-9 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
                      <Boxes className="size-4" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium">{p.name}</div>
                      <div className="truncate font-mono text-xs text-muted-foreground">
                        {p.code} · {p.key_prefix}
                      </div>
                    </div>
                    {!p.is_active && <Badge variant="outline">Pasif</Badge>}
                  </button>
                </li>
              ))}
            </ul>
          </Card>
          {selected && <ProductDetail key={selected.id} product={selected} reload={reload} />}
        </div>
      )}
    </div>
  );
}

function NewProductCard({ onDone }: { onDone: (id: number) => void }) {
  const [form, setForm] = useState({ code: "", name: "", key_prefix: "" });
  const { run, busy, message, err } = useSubmit(() => {});

  async function submit(e: FormEvent) {
    e.preventDefault();
    await run(async () => {
      const res = await api<{ data: CatalogProduct }>("/catalog/products", { method: "POST", body: form });
      onDone(res.data.id);
    });
  }

  return (
    <Card className="border-primary/40">
      <CardHeader>
        <CardTitle>Yeni ürün</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-[1fr_1fr_140px_auto] sm:items-end" noValidate>
          {message && <div className="sm:col-span-4"><Alert>{message}</Alert></div>}
          <Field label="Ad *" error={err("name")}>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Mezbaha" maxLength={100} />
          </Field>
          <Field label="Kod *" error={err("code")} hint="Büyük harf; ürün yazılımı bu kodu kullanır">
            <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="MEZBAHA" maxLength={30} className="font-mono" />
          </Field>
          <Field label="Anahtar ön eki *" error={err("key_prefix")} hint="Ör. MEZ-XXXX-…">
            <Input value={form.key_prefix} onChange={(e) => setForm({ ...form, key_prefix: e.target.value.toUpperCase() })} placeholder="MEZ" maxLength={5} className="font-mono" />
          </Field>
          <Button type="submit" disabled={busy}>
            <Save /> Oluştur
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function ProductDetail({ product, reload }: { product: CatalogProduct; reload: () => void }) {
  const [tab, setTab] = useState<Tab>("packages");
  const [name, setName] = useState(product.name);
  const { run, busy, message, err } = useSubmit(reload);
  const activePackages = product.packages.filter((p) => p.is_active);

  const patch = (body: Record<string, unknown>) =>
    run(() => api(`/catalog/products/${product.id}`, { method: "PATCH", body }));

  return (
    <div className="min-w-0 space-y-4">
      <Card>
        <CardContent className="space-y-4">
          {message && <Alert>{message}</Alert>}
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-48 flex-1">
              <Field label="Ürün adı" error={err("name")}>
                <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={100} />
              </Field>
            </div>
            <Button variant="outline" disabled={busy || name.trim() === product.name} onClick={() => patch({ name: name.trim() })}>
              <Save /> Kaydet
            </Button>
            <ActiveToggle active={product.is_active} disabled={busy} onChange={(v) => patch({ is_active: v })} />
          </div>
          <div className="grid gap-3 text-sm sm:grid-cols-3">
            <Info label="Kod"><span className="font-mono">{product.code}</span></Info>
            <Info label="Anahtar ön eki"><span className="font-mono">{product.key_prefix}-XXXX-XXXX-XXXX</span></Info>
            <Field label="Demo paketi" error={err("demo_package_id")}>
              <select
                value={product.demo_package_id ?? ""}
                disabled={busy}
                onChange={(e) => patch({ demo_package_id: e.target.value ? Number(e.target.value) : null })}
                className="h-9 w-full rounded-lg border bg-card px-3 text-sm outline-none focus:border-primary"
              >
                <option value="">Demo yok</option>
                {activePackages.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </Field>
          </div>
        </CardContent>
      </Card>

      <div className="flex w-fit rounded-lg border bg-card p-0.5 text-sm">
        {([["packages", `Paketler (${product.packages.length})`, Package], ["modules", `Modüller (${product.modules.length})`, Puzzle]] as const).map(
          ([value, label, Icon]) => (
            <button
              key={value}
              type="button"
              onClick={() => setTab(value)}
              className={cn(
                "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium",
                tab === value ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
              )}
            >
              <Icon className="size-4" /> {label}
            </button>
          ),
        )}
      </div>

      {tab === "packages" ? <PackagesTab product={product} reload={reload} /> : <ModulesTab product={product} reload={reload} />}
    </div>
  );
}

function Info({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <div className="text-xs font-medium text-muted-foreground">{label}</div>
      <div className="flex h-9 items-center">{children}</div>
    </div>
  );
}

function ActiveToggle({ active, disabled, onChange }: { active: boolean; disabled?: boolean; onChange: (v: boolean) => void }) {
  return (
    <Button variant="outline" disabled={disabled} onClick={() => onChange(!active)} className={active ? "" : "text-muted-foreground"}>
      {active ? <Check /> : null} {active ? "Aktif" : "Pasif"}
    </Button>
  );
}

const toggle = (ids: number[], id: number) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]);

/** Modül seçimi: kutucuklar. Pasif modüller seçilebilir ama imzalı belgeye girmez. */
function ModulePicker({ modules, value, onToggle, disabled }: {
  modules: CatalogModule[];
  value: number[];
  onToggle: (id: number) => void;
  disabled?: boolean;
}) {
  if (modules.length === 0) return <p className="text-xs text-muted-foreground">Bu ürüne henüz modül tanımlanmadı (Modüller sekmesi).</p>;
  return (
    <div className="flex flex-wrap gap-2">
      {modules.map((m) => {
        const on = value.includes(m.id);
        return (
          <button
            key={m.id}
            type="button"
            disabled={disabled}
            aria-pressed={on}
            onClick={() => onToggle(m.id)}
            title={m.description ?? m.code}
            className={cn(
              "inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors",
              on ? "border-primary bg-accent text-accent-foreground" : "bg-card text-muted-foreground hover:bg-muted/60",
              !m.is_active && "line-through opacity-60",
            )}
          >
            {on && <Check className="size-3.5" />}
            {m.name}
          </button>
        );
      })}
    </div>
  );
}

function PackagesTab({ product, reload }: { product: CatalogProduct; reload: () => void }) {
  const [form, setForm] = useState({ code: "", name: "", default_device_limit: 1 });
  const { run, busy, message, err } = useSubmit(reload);

  async function create(e: FormEvent) {
    e.preventDefault();
    await run(
      () => api(`/catalog/products/${product.id}/packages`, {
        method: "POST",
        body: { ...form, sort_order: (product.packages.length + 1) * 10, module_ids: [] },
      }),
      () => setForm({ code: "", name: "", default_device_limit: 1 }),
    );
  }

  return (
    <div className="space-y-3">
      {product.packages.map((p) => (
        <PackageCard key={p.id} pkg={p} product={product} reload={reload} />
      ))}
      <Card>
        <CardHeader>
          <CardTitle>Yeni paket</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={create} className="grid gap-3 sm:grid-cols-[1fr_1fr_120px_auto] sm:items-end" noValidate>
            {message && <div className="sm:col-span-4"><Alert>{message}</Alert></div>}
            <Field label="Ad *" error={err("name")}>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Restaurant" maxLength={100} />
            </Field>
            <Field label="Kod *" error={err("code")}>
              <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} placeholder="RESTAURANT" maxLength={30} className="font-mono" />
            </Field>
            <Field label="Cihaz limiti" error={err("default_device_limit")}>
              <Input type="number" min={1} max={1000} value={form.default_device_limit} onChange={(e) => setForm({ ...form, default_device_limit: Number(e.target.value) })} />
            </Field>
            <Button type="submit" variant="outline" disabled={busy}>
              <Plus /> Ekle
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

function PackageCard({ pkg, product, reload }: { pkg: CatalogPackage; product: CatalogProduct; reload: () => void }) {
  const [form, setForm] = useState({ name: pkg.name, default_device_limit: pkg.default_device_limit, module_ids: pkg.module_ids });
  const { run, busy, message, err } = useSubmit(reload);
  useEffect(() => setForm({ name: pkg.name, default_device_limit: pkg.default_device_limit, module_ids: pkg.module_ids }), [pkg]);

  const dirty =
    form.name !== pkg.name ||
    form.default_device_limit !== pkg.default_device_limit ||
    [...form.module_ids].sort().join() !== [...pkg.module_ids].sort().join();
  const save = (body: Record<string, unknown>) => run(() => api(`/catalog/packages/${pkg.id}`, { method: "PATCH", body }));
  const isDemo = product.demo_package_id === pkg.id;

  return (
    <Card className={cn(!pkg.is_active && "opacity-70")}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Package className="size-4 text-muted-foreground" /> {pkg.name}
          <span className="font-mono text-xs font-normal text-muted-foreground">{pkg.code}</span>
          {isDemo && <Badge>Demo paketi</Badge>}
          {!pkg.is_active && <Badge variant="outline">Pasif</Badge>}
        </CardTitle>
        <ActiveToggle active={pkg.is_active} disabled={busy} onChange={(v) => save({ is_active: v })} />
      </CardHeader>
      <CardContent className="space-y-3">
        {message && <Alert>{message}</Alert>}
        <div className="grid gap-3 sm:grid-cols-[1fr_160px]">
          <Field label="Ad" error={err("name")}>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={100} />
          </Field>
          <Field label="Varsayılan cihaz limiti" error={err("default_device_limit")}>
            <Input type="number" min={1} max={1000} value={form.default_device_limit} onChange={(e) => setForm({ ...form, default_device_limit: Number(e.target.value) })} />
          </Field>
        </div>
        <div className="space-y-1.5">
          <div className="text-xs font-medium text-muted-foreground">Modüller ({form.module_ids.length})</div>
          <ModulePicker modules={product.modules} value={form.module_ids} onToggle={(id) => setForm((f) => ({ ...f, module_ids: toggle(f.module_ids, id) }))} disabled={busy} />
        </div>
        {dirty && (
          <div className="flex flex-wrap items-center gap-3">
            <Button size="sm" disabled={busy} onClick={() => save(form)}>
              <Save /> Paketi kaydet
            </Button>
            <span className="text-xs text-muted-foreground">Bu paketteki lisanslar yeni modülleri cihazın sonraki online kontrolünde alır.</span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function ModulesTab({ product, reload }: { product: CatalogProduct; reload: () => void }) {
  const [form, setForm] = useState({ code: "", name: "", description: "" });
  const { run, busy, message, err } = useSubmit(reload);

  async function create(e: FormEvent) {
    e.preventDefault();
    await run(
      () => api(`/catalog/products/${product.id}/modules`, { method: "POST", body: { ...form, description: form.description || null } }),
      () => setForm({ code: "", name: "", description: "" }),
    );
  }

  return (
    <div className="space-y-3">
      <Card className="overflow-hidden">
        {product.modules.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted-foreground">Henüz modül yok.</p>
        ) : (
          <ul className="divide-y">
            {product.modules.map((m) => (
              <ModuleRow key={m.id} module={m} usedBy={product.packages.filter((p) => p.module_ids.includes(m.id)).map((p) => p.name)} reload={reload} />
            ))}
          </ul>
        )}
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Yeni modül</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={create} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" noValidate>
            {message && <div className="sm:col-span-3"><Alert>{message}</Alert></div>}
            <Field label="Ad *" error={err("name")}>
              <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Varyantlı ürün" maxLength={100} />
            </Field>
            <Field label="Kod *" error={err("code")} hint="Küçük harf; yazılım bu kodu kontrol eder">
              <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toLowerCase() })} placeholder="variant" maxLength={40} className="font-mono" />
            </Field>
            <Button type="submit" variant="outline" disabled={busy}>
              <Plus /> Ekle
            </Button>
            <div className="sm:col-span-3">
              <Field label="Açıklama" error={err("description")}>
                <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} maxLength={255} />
              </Field>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

function ModuleRow({ module, usedBy, reload }: { module: CatalogModule; usedBy: string[]; reload: () => void }) {
  const [name, setName] = useState(module.name);
  const { run, busy, message } = useSubmit(reload);
  const save = (body: Record<string, unknown>) => run(() => api(`/catalog/modules/${module.id}`, { method: "PATCH", body }));

  return (
    <li className={cn("space-y-2 px-4 py-3", !module.is_active && "opacity-70")}>
      {message && <Alert>{message}</Alert>}
      <div className="flex flex-wrap items-center gap-3">
        <Puzzle className="size-4 shrink-0 text-muted-foreground" />
        <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={100} className="h-8 w-56" />
        <span className="font-mono text-xs text-muted-foreground">{module.code}</span>
        <span className="flex flex-wrap gap-1">
          {usedBy.length ? usedBy.map((p) => <Badge key={p} variant="outline">{p}</Badge>) : <span className="text-xs text-muted-foreground">Pakette yok</span>}
        </span>
        <span className="ml-auto flex gap-2">
          {name.trim() !== module.name && (
            <Button size="sm" variant="outline" disabled={busy} onClick={() => save({ name: name.trim() })}>
              <Save /> Kaydet
            </Button>
          )}
          <ActiveToggle active={module.is_active} disabled={busy} onChange={(v) => save({ is_active: v })} />
        </span>
      </div>
      {module.description && <p className="pl-7 text-xs text-muted-foreground">{module.description}</p>}
    </li>
  );
}
