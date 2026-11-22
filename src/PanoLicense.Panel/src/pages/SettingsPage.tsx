import { KeyRound, UserPlus } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Alert, Field, Input } from "@/components/ui/input";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";
import { useApi } from "@/lib/useApi";

interface PanelUser {
  id: number;
  name: string;
  username: string;
  role: "admin" | "user";
  is_active: boolean;
  last_login_at: string | null;
  permissions: string[];
}
interface PermissionOption {
  value: string;
  label: string;
  default: boolean;
}
interface UsersResponse {
  data: PanelUser[];
  available_permissions: PermissionOption[];
}

export function SettingsPage() {
  const { data, error, loading, reload } = useApi<UsersResponse>("/users");
  const [editing, setEditing] = useState<PanelUser | null>(null);

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">Ayarlar · Kullanıcılar</h1>
      {error && <Alert>{error}</Alert>}
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <Card className="overflow-hidden">
          <CardHeader className="pb-3">
            <CardTitle>Kullanıcılar</CardTitle>
          </CardHeader>
          {loading ? (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">Yükleniyor…</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[620px] text-sm">
                <thead>
                  <tr className="border-b text-left text-xs text-muted-foreground">
                    <th className="px-4 py-2.5 font-medium">Ad</th>
                    <th className="px-4 py-2.5 font-medium">Kullanıcı adı</th>
                    <th className="px-4 py-2.5 font-medium">Rol</th>
                    <th className="px-4 py-2.5 font-medium">Son giriş</th>
                    <th className="px-4 py-2.5 font-medium">Durum</th>
                    <th className="px-4 py-2.5" />
                  </tr>
                </thead>
                <tbody>
                  {data?.data.map((u) => (
                    <tr key={u.id} className="border-b last:border-0">
                      <td className="px-4 py-3 font-medium">{u.name}</td>
                      <td className="px-4 py-3 font-mono text-xs">{u.username}</td>
                      <td className="px-4 py-3">
                        <Badge variant={u.role === "admin" ? "solid" : "default"}>{u.role === "admin" ? "Admin" : "Kullanıcı"}</Badge>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{formatDateTime(u.last_login_at)}</td>
                      <td className="px-4 py-3">
                        <Badge variant={u.is_active ? "success" : "outline"}>{u.is_active ? "Aktif" : "Pasif"}</Badge>
                      </td>
                      <td className="px-4 py-3 text-right">
                        {u.role === "user" && (
                          <Button size="sm" variant="outline" onClick={() => setEditing(u)}>
                            Düzenle
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

        {data &&
          (editing ? (
            <EditUserCard
              key={editing.id}
              user={editing}
              options={data.available_permissions}
              onClose={() => setEditing(null)}
              onSaved={() => {
                setEditing(null);
                reload();
              }}
            />
          ) : (
            <NewUserCard options={data.available_permissions} onCreated={reload} />
          ))}
      </div>
    </div>
  );
}

function PermissionChecklist({ options, value, onChange }: { options: PermissionOption[]; value: string[]; onChange: (v: string[]) => void }) {
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-xs font-medium text-muted-foreground">Yetkiler</legend>
      {options.map((o) => (
        <label key={o.value} className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="size-4 accent-[var(--primary)]"
            checked={value.includes(o.value)}
            onChange={(e) => onChange(e.target.checked ? [...value, o.value] : value.filter((v) => v !== o.value))}
          />
          {o.label}
        </label>
      ))}
    </fieldset>
  );
}

function NewUserCard({ options, onCreated }: { options: PermissionOption[]; onCreated: () => void }) {
  const defaults = options.filter((o) => o.default).map((o) => o.value);
  const [form, setForm] = useState({ name: "", username: "", password: "" });
  const [permissions, setPermissions] = useState<string[]>(defaults);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setNotice(null);
    try {
      await api("/users", { method: "POST", body: { ...form, username: form.username.trim().toLowerCase(), permissions } });
      setNotice(`${form.name} oluşturuldu. Kullanıcı adı ve parolayı kendisine güvenli bir yoldan iletin.`);
      setForm({ name: "", username: "", password: "" });
      setPermissions(defaults);
      onCreated();
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.keys(e.errors).length ? e.errors : { _: [e.message] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Yeni kullanıcı</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-3" noValidate>
          {errors._ && <Alert>{errors._[0]}</Alert>}
          {notice && <Alert kind="success">{notice}</Alert>}
          <Field label="Ad Soyad" error={errors.name?.[0]}>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={100} />
          </Field>
          <Field label="Kullanıcı adı" error={errors.username?.[0]} hint="Küçük harf, rakam, nokta, tire">
            <Input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoCapitalize="none" maxLength={50} />
          </Field>
          <Field label="Parola" error={errors.password?.[0]} hint="En az 10 karakter, harf ve rakam">
            <Input type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
          <PermissionChecklist options={options} value={permissions} onChange={setPermissions} />
          <Button type="submit" disabled={busy}>
            <UserPlus /> Kullanıcı oluştur
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function EditUserCard({ user, options, onClose, onSaved }: { user: PanelUser; options: PermissionOption[]; onClose: () => void; onSaved: () => void }) {
  const { me } = useAuth();
  const [permissions, setPermissions] = useState(user.permissions);
  const [isActive, setIsActive] = useState(user.is_active);
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => setPermissions(user.permissions), [user]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await api(`/users/${user.id}`, {
        method: "PATCH",
        body: { permissions, is_active: isActive, ...(password ? { password } : {}) },
      });
      onSaved();
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.keys(e.errors).length ? e.errors : { _: [e.message] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{user.name}</CardTitle>
        <button type="button" onClick={onClose} className="text-xs text-muted-foreground hover:text-foreground">
          Kapat
        </button>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-3" noValidate>
          {(errors._ || errors.is_active) && <Alert>{(errors._ ?? errors.is_active)[0]}</Alert>}
          <PermissionChecklist options={options} value={permissions} onChange={setPermissions} />
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4" checked={isActive} disabled={user.id === me?.id} onChange={(e) => setIsActive(e.target.checked)} />
            Hesap aktif (pasif kullanıcı giriş yapamaz; kayıt silinmez)
          </label>
          <Field label="Yeni parola (isteğe bağlı)" error={errors.password?.[0]} hint="Boş bırakılırsa değişmez">
            <Input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </Field>
          <Button type="submit" disabled={busy}>
            <KeyRound /> Kaydet
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
