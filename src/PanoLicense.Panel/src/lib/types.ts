export type LicenseState = "demo" | "demo_expired" | "active" | "expiring" | "expired" | "revoked";

export interface Person {
  id: number;
  name: string;
  username?: string | null;
}

export interface Device {
  id: number;
  name: string | null;
  app_version: string | null;
  status: "active" | "released";
  fingerprint_short: string;
  first_seen_at: string;
  last_seen_at: string;
  released_at: string | null;
}

export interface License {
  id: number;
  license_key: string;
  customer: {
    id: number;
    name: string;
    city: string | null;
    phone: string | null;
    email: string | null;
    tax_number: string | null;
  };
  product: { id: number; name: string };
  device_limit: number;
  starts_at: string;
  expires_at: string;
  status: "demo" | "active" | "revoked";
  state: LicenseState;
  source: "panel" | "demo";
  notes: string | null;
  /** Lisansı veren (panelden veren veya demoyu onaylayan). Onaysız demoda null. */
  created_by: Person | null;
  /** Yalnız demodan gelenlerde: PanoPOS'a yazılan kullanıcı adı ve eşleşen panel kullanıcısı. */
  installer: { username: string | null; user: Person | null } | null;
  approved_by: Person | null;
  approved_at: string | null;
  created_at: string;
  revoked_at: string | null;
  revoked_by?: Person | null;
  revoke_reason: string | null;
  active_device_count?: number;
  devices?: Device[];
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
  current_page?: number;
  last_page?: number;
  total?: number;
}

export interface Customer {
  id: number;
  name: string;
  city: string | null;
  phone: string | null;
  active_license_count: number;
  created_by: string | null;
}

export interface Product {
  id: number;
  code: string;
  name: string;
}
