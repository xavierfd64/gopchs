import { cn } from "@/lib/utils";

interface SectionHeadingProps {
  eyebrow?: string;
  title: string;
  description?: string;
  align?: "left" | "center";
  tone?: "dark" | "light";
  className?: string;
}

export function SectionHeading({
  eyebrow,
  title,
  description,
  align = "left",
  tone = "dark",
  className,
}: SectionHeadingProps) {
  return (
    <div
      className={cn(
        "max-w-2xl",
        align === "center" && "mx-auto text-center",
        className,
      )}
    >
      {eyebrow && (
        <p
          className={cn(
            "mb-2 text-xs font-bold uppercase tracking-[0.2em]",
            tone === "dark" ? "text-pchs-gold-600" : "text-pchs-gold-300",
          )}
        >
          {eyebrow}
        </p>
      )}
      <h2
        className={cn(
          "text-2xl font-bold sm:text-3xl",
          tone === "dark" ? "text-pchs-green-900" : "text-white",
        )}
      >
        {title}
      </h2>
      {description && (
        <p
          className={cn(
            "mt-3 text-base leading-relaxed",
            tone === "dark" ? "text-black/70" : "text-white/80",
          )}
        >
          {description}
        </p>
      )}
    </div>
  );
}
