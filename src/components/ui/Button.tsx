import Link from "next/link";
import { cn } from "@/lib/utils";
import { ArrowRightIcon } from "@/components/ui/icons";

type ButtonVariant = "primary" | "secondary" | "outline" | "ghost";
type ButtonSize = "sm" | "md" | "lg";

const variantClasses: Record<ButtonVariant, string> = {
  primary:
    "bg-pchs-gold-500 text-pchs-green-950 hover:bg-pchs-gold-400 focus-visible:outline-pchs-gold-500",
  secondary:
    "bg-pchs-green-800 text-white hover:bg-pchs-green-700 focus-visible:outline-pchs-green-700",
  outline:
    "border-2 border-white text-white hover:bg-white hover:text-pchs-green-900 focus-visible:outline-white",
  ghost:
    "text-pchs-green-800 hover:bg-pchs-green-800/10 focus-visible:outline-pchs-green-700",
};

const sizeClasses: Record<ButtonSize, string> = {
  sm: "px-4 py-2 text-sm",
  md: "px-6 py-3 text-sm",
  lg: "px-8 py-4 text-base",
};

const baseClasses =
  "inline-flex items-center justify-center gap-2 rounded-full font-semibold uppercase tracking-wide transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-50";

interface CommonProps {
  variant?: ButtonVariant;
  size?: ButtonSize;
  className?: string;
  children: React.ReactNode;
}

type ButtonAsButton = CommonProps &
  React.ButtonHTMLAttributes<HTMLButtonElement> & { href?: undefined };

type ButtonAsLink = CommonProps &
  Omit<React.ComponentProps<typeof Link>, "href" | "className"> & {
    href: string;
  };

type ButtonProps = ButtonAsButton | ButtonAsLink;

const arrowCircleSize: Record<ButtonSize, string> = {
  sm: "h-5 w-5",
  md: "h-6 w-6",
  lg: "h-7 w-7",
};

export function Button({
  variant = "primary",
  size = "md",
  className,
  children,
  ...props
}: ButtonProps) {
  const classes = cn(
    baseClasses,
    variantClasses[variant],
    sizeClasses[size],
    className,
  );

  const content =
    variant === "primary" ? (
      <>
        {children}
        <span
          className={cn(
            "flex shrink-0 items-center justify-center rounded-full bg-pchs-green-950 text-white",
            arrowCircleSize[size],
          )}
        >
          <ArrowRightIcon className="h-3.5 w-3.5" strokeWidth={2.5} />
        </span>
      </>
    ) : (
      children
    );

  if ("href" in props && props.href) {
    const { href, ...rest } = props;
    return (
      <Link href={href} className={classes} {...rest}>
        {content}
      </Link>
    );
  }

  return (
    <button className={classes} {...(props as React.ButtonHTMLAttributes<HTMLButtonElement>)}>
      {content}
    </button>
  );
}
