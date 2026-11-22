import { ArrowLeft } from "lucide-react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import type { NavItem } from "@/lib/navigation";

export function EmptyStatePage({ item }: { item: NavItem }) {
  const Icon = item.icon;
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-bold">{item.label}</h1>
      <Card className="flex flex-col items-center px-6 py-16 text-center">
        <div className="mb-4 grid size-14 place-items-center rounded-2xl bg-accent text-accent-foreground">
          <Icon className="size-7" />
        </div>
        <Badge variant="outline" className="mb-3">
          Planlanan faz: {item.phase}
        </Badge>
        <h2 className="text-base font-semibold">{item.label} henüz geliştirilmedi</h2>
        <p className="mt-2 max-w-md text-sm text-muted-foreground">{item.description}</p>
        <Button asChild variant="outline" className="mt-6">
          <Link to="/">
            <ArrowLeft /> Dashboard'a dön
          </Link>
        </Button>
      </Card>
    </div>
  );
}
