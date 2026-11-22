export type LicenseState = "active" | "expiring" | "expired" | "revoked";

export interface License {
  id: number;
  license_key: string;
  customer: { id: number; name: string; city: string | null };
  product: { id: number; name: string };
  device_limit: number;
  starts_at: string;
  expires_at: string;
  status: "active" | "revoked";
  state: LicenseState;
  notes: string | null;
  created_by: { id: number; name: string };
  created_at: string;
  revoked_at: string | null;
  revoked_by?: { id: number; name: string } | null;
  revoke_reason: string | null;
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
