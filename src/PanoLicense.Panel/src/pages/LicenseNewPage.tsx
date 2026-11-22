import { ArrowLeft, Save } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input, Textarea } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { isoDatePlus } from "@/lib/format";
import type { Customer, License, Paginated, Product } from "@/lib/types";
import { useApi } from "@/lib/useApi";
import { cn } from "@/lib/utils";

type Mode = "new" | "existing";

export function LicenseNewPage() {
  const navigate = useNavigate();
  const products = useApi<Product[]>("/products");

  const [mode, setMode] = useState<Mode>("new");
  const [customer, setCustomer] = useState({ name: "", city: "", phone: "", email: "", tax_number: "" });
  const [customerQuery, setCustomerQuery] = useState("");
  const [selected, setSelected] = useState<Customer | null>(null);
  const [productId, setProductId] = useState<number | "">("");
  const [deviceLimit, setDeviceLimit] = useState(1);
  const [startsAt, setStartsAt] = useState(isoDatePlus(0));
  const [expiresAt, setExpiresAt] = useState(isoDatePlus(365));
  const [notes, setNotes] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const search = useApi<Paginated<Customer>>(
    mode === "existing" && customerQuery.trim().length >= 2 ? `/customers?q=${encodeURIComponent(customerQuery.trim())}` : null,
  );

  useEffect(() => {
    if (products.data?.length && productId === "") setProductId(products.data[0].id);
  }, [products.data, productId]);

  const err = (key: string) => errors[key]?.[0];

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const body: Record<string, unknown> = {
      product_id: productId,
      device_limit: deviceLimit,
      starts_at: startsAt,
      expires_at: expiresAt,
      notes: notes || null,
    };
    if (mode === "existing") body.customer_id = selected?.id ?? null;
    else body.customer = Object.fromEntries(Object.entries(customer).map(([k, v]) => [k, v.trim() || null]));

    try {
      const res = await api<{ data: License }>("/licenses", { method: "POST", body });
      navigate(`/lisanslar/${res.data.id}`, { replace: true, state: { created: true } });
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
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to="/lisanslar" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> Lisanslar
      </Link>
      <h1 className="text-xl font-bold">Yeni Lisans</h1>

      <form onSubmit={submit} className="space-y-4" noValidate>
        {message && <Alert>{message}</Alert>}

        <Card>
          <CardHeader>
            <CardTitle>Firma</CardTitle>
            <div className="flex rounded-lg border p-0.5 text-xs">
              {(["new", "existing"] as Mode[]).map((m) => (
                <button
                  key={m}
                  type="button"
                  onClick={() => setMode(m)}
                  className={cn("rounded-md px-3 py-1 font-medium", mode === m ? "bg-primary text-primary-foreground" : "text-muted-foreground")}
                >
                  {m === "new" ? "Yeni firma" : "Kayıtlı firma"}
                </button>
              ))}
            </div>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            {mode === "new" ? (
              <>
                <div className="sm:col-span-2">
                  <Field label="Firma adı *" error={err("customer.name") ?? err("customer")}>
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
              </>
            ) : (
              <div className="space-y-2 sm:col-span-2">
                <Field label="Firma ara" error={err("customer_id")}>
                  <Input
                    value={customerQuery}
                    onChange={(e) => {
                      setCustomerQuery(e.target.value);
                      setSelected(null);
                    }}
                    placeholder="En az 2 karakter"
                  />
                </Field>
                {selected ? (
                  <div className="flex items-center justify-between rounded-lg border bg-accent px-3 py-2 text-sm">
                    <span>
                      <b>{selected.name}</b> {selected.city && <span className="text-muted-foreground">· {selected.city}</span>}
                    </span>
                    <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setSelected(null)}>
                      Değiştir
                    </button>
                  </div>
                ) : (
                  search.data && (
                    <ul className="max-h-56 divide-y overflow-y-auto rounded-lg border">
                      {search.data.data.length === 0 && <li className="px-3 py-2 text-sm text-muted-foreground">Firma bulunamadı.</li>}
                      {search.data.data.map((c) => (
                        <li key={c.id}>
                          <button type="button" onClick={() => setSelected(c)} className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted/60">
                            <span>
                              {c.name} {c.city && <span className="text-muted-foreground">· {c.city}</span>}
                            </span>
                            <span className="text-xs text-muted-foreground">{c.active_license_count} aktif lisans</span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )
                )}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Lisans</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <Field label="Ürün *" error={err("product_id")}>
              <select
                value={productId}
                onChange={(e) => setProductId(Number(e.target.value))}
                className="h-9 w-full rounded-lg border bg-card px-3 text-sm outline-none focus:border-primary"
              >
                {products.data?.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Cihaz limiti *" error={err("device_limit")} hint="Firmanın satın aldığı cihaz sayısı">
              <Input type="number" min={1} max={1000} value={deviceLimit} onChange={(e) => setDeviceLimit(Number(e.target.value))} />
            </Field>
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
          </CardContent>
        </Card>

        <div className="flex justify-end gap-2">
          <Button asChild variant="outline">
            <Link to="/lisanslar">Vazgeç</Link>
          </Button>
          <Button type="submit" disabled={busy || (mode === "existing" && !selected)}>
            <Save /> {busy ? "Kaydediliyor…" : "Lisansı oluştur"}
          </Button>
        </div>
      </form>
    </div>
  );
}
