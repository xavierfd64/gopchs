import Link from "next/link";
import { cn } from "@/lib/utils";
import type { PortalDefinition } from "@/lib/config";
import {
  GraduationCapIcon,
  CalendarCheckIcon,
  ClipboardCheckIcon,
  UsersIcon,
  ChalkboardIcon,
  UserIcon,
  NewspaperIcon,
  NetworkIcon,
} from "@/components/ui/icons";

const iconByKey: Record<string, React.ComponentType<React.SVGProps<SVGSVGElement>>> = {
  lms: GraduationCapIcon,
  attendance: CalendarCheckIcon,
  grading: ClipboardCheckIcon,
  parents: UsersIcon,
  teachers: ChalkboardIcon,
  students: UserIcon,
  publication: NewspaperIcon,
  alumni: NetworkIcon,
};

interface PortalCardProps {
  portal: PortalDefinition;
  variant?: "bar" | "grid";
}

export function PortalCard({ portal, variant = "grid" }: PortalCardProps) {
  const Icon = iconByKey[portal.key] ?? GraduationCapIcon;
  const isLive = portal.status === "live";
  const target = isLive ? portal.href : "/portals";

  if (variant === "bar") {
    return (
      <Link
        href={target}
        className="group flex min-w-[132px] shrink-0 flex-col items-center gap-2 rounded-xl px-3 py-3 text-center transition-colors hover:bg-white/10"
      >
        <Icon className="h-7 w-7 text-pchs-gold-400" />
        <span className="text-xs font-bold uppercase leading-tight tracking-wide text-white">
          {portal.name}
        </span>
        <span className="text-[11px] text-white/60">{portal.description}</span>
      </Link>
    );
  }

  return (
    <Link
      href={target}
      className={cn(
        "group flex flex-col gap-3 rounded-card border border-black/5 bg-white p-5 shadow-sm transition-shadow hover:shadow-md",
      )}
    >
      <span className="flex h-11 w-11 items-center justify-center rounded-full bg-pchs-green-800/10 text-pchs-green-800">
        <Icon className="h-5 w-5" />
      </span>
      <span className="font-display text-base font-bold text-pchs-green-900">
        {portal.name}
      </span>
      <span className="text-sm text-black/60">{portal.description}</span>
      <span
        className={cn(
          "mt-1 inline-flex w-fit items-center rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide",
          isLive
            ? "bg-pchs-green-800/10 text-pchs-green-800"
            : "bg-pchs-gold-500/15 text-pchs-gold-600",
        )}
      >
        {isLive ? "Open Portal" : "Coming Soon"}
      </span>
    </Link>
  );
}
