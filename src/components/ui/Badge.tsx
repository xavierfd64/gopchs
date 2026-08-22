import { cn } from "@/lib/utils";

type BadgeTone = "gold" | "green" | "neutral";

const toneClasses: Record<BadgeTone, string> = {
  gold: "bg-pchs-gold-500/15 text-pchs-gold-600",
  green: "bg-pchs-green-800/10 text-pchs-green-800",
  neutral: "bg-black/5 text-black/60",
};

export function Badge({
  tone = "neutral",
  className,
  children,
}: {
  tone?: BadgeTone;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide",
        toneClasses[tone],
        className,
      )}
    >
      {children}
    </span>
  );
}
