import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

type Tone = "primary" | "navy" | "warning" | "danger";

const toneClasses: Record<Tone, string> = {
  primary: "bg-accent text-accent-foreground",
  navy: "bg-chart-1 text-primary-foreground dark:text-background",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
};

interface StatCardProps {
  title: string;
  value: string;
  icon: LucideIcon;
  tone?: Tone;
  footer: ReactNode;
}

export function StatCard({ title, value, icon: Icon, tone = "primary", footer }: StatCardProps) {
  return (
    <Card className="min-w-0 p-4">
      <div className="flex items-center gap-2.5">
        <div className={cn("grid size-9 shrink-0 place-items-center rounded-lg", toneClasses[tone])}>
          <Icon className="size-[18px]" />
        </div>
        <div className="text-xs font-medium leading-tight text-muted-foreground">{title}</div>
      </div>
      <div className="mt-3 text-2xl font-bold tracking-tight tabular-nums">{value}</div>
      <div className="mt-0.5 truncate text-xs">{footer}</div>
    </Card>
  );
}
