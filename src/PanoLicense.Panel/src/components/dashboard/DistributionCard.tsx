import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { toPercentages } from "@/lib/percent";

interface Slice {
  label: string;
  value: number;
  color: string;
}

const RADIUS = 40;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 1.5;

/** Hafif SVG halka grafik; grafik kütüphanesi kullanılmaz. */
export function DistributionCard({ title, slices }: { title: string; slices: Slice[] }) {
  const total = slices.reduce((sum, s) => sum + s.value, 0);
  const percents = toPercentages(slices.map((s) => s.value));

  let offset = 0;
  const arcs = slices.map((s) => {
    const length = total ? (s.value / total) * CIRCUMFERENCE : 0;
    const arc = { ...s, dash: Math.max(length - GAP, 0), offset };
    offset += length;
    return arc;
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex items-center gap-4">
        <div className="relative size-28 shrink-0">
          <svg viewBox="0 0 100 100" className="size-full -rotate-90" role="img" aria-label={`${title}: toplam ${total}`}>
            <circle cx="50" cy="50" r={RADIUS} fill="none" stroke="var(--muted)" strokeWidth="12" />
            {arcs.map((a) => (
              <circle
                key={a.label}
                cx="50"
                cy="50"
                r={RADIUS}
                fill="none"
                stroke={a.color}
                strokeWidth="12"
                strokeDasharray={`${a.dash} ${CIRCUMFERENCE - a.dash}`}
                strokeDashoffset={-a.offset}
              />
            ))}
          </svg>
          <div className="absolute inset-0 grid place-content-center text-center">
            <div className="text-xl font-bold tabular-nums">{total}</div>
            <div className="text-[11px] text-muted-foreground">Aktif</div>
          </div>
        </div>
        <ul className="min-w-0 flex-1 space-y-2 text-xs">
          {slices.map((s, i) => (
            <li key={s.label} className="flex items-center gap-2">
              <span className="size-2.5 shrink-0 rounded-full" style={{ background: s.color }} />
              <span className="truncate">{s.label}</span>
              <span className="ml-auto whitespace-nowrap tabular-nums text-muted-foreground">
                {s.value} (%{percents[i]})
              </span>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
