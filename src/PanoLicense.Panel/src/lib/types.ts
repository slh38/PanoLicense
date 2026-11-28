export type LicenseState = "demo" | "demo_expired" | "active" | "expiring" | "expired" | "revoked" | "invalid";

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
  /** Paket (yoksa null). */
  package?: { id: number; code: string; name: string } | null;
  /** Yalnız detayda: geçerli modüller = paket + lisansa özel ek modüller. İmzalı belgeye aynı kodlar girer. */
  modules?: { id: number; code: string; name: string; source: "package" | "license" }[];
  addon_module_ids?: number[];
  /** Lisans bazlı offline çalışma izni (gün): 7 standart, 365 özel. Demo daima 7. */
  offline_grace_days: number;
  device_limit: number;
  starts_at: string;
  /** FAZ 6.2a: süreli (demo dahil) veya ömür boyu. Ömür boyu yalnız açık tür + null bitişle geçerlidir. */
  license_type: "term" | "perpetual";
  /** Süreli lisansta YYYY-MM-DD; ömür boyunda null. */
  expires_at: string | null;
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

export interface ModuleRef {
  code: string;
  name: string;
}

export interface ProductPackage {
  id: number;
  code: string;
  name: string;
  default_device_limit: number;
  modules: ModuleRef[];
}

/** /products: lisans formu için aktif ürünler ve aktif paketleri. */
export interface Product {
  id: number;
  code: string;
  name: string;
  packages: ProductPackage[];
}

/** /catalog: Admin katalog yönetimi (pasifler dahil). */
export interface CatalogModule {
  id: number;
  product_id: number;
  code: string;
  name: string;
  description: string | null;
  is_active: boolean;
}

export interface CatalogPackage {
  id: number;
  product_id: number;
  code: string;
  name: string;
  default_device_limit: number;
  sort_order: number;
  is_active: boolean;
  module_ids: number[];
}

export interface CatalogProduct {
  id: number;
  code: string;
  name: string;
  key_prefix: string;
  is_active: boolean;
  demo_package_id: number | null;
  packages: CatalogPackage[];
  modules: CatalogModule[];
}
