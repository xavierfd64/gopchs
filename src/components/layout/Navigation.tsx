"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { primaryNav } from "@/lib/navigation";
import { ChevronDownIcon } from "@/components/ui/icons";
import { cn } from "@/lib/utils";

function isActiveHref(pathname: string, href: string) {
  if (href === "/") return pathname === "/";
  return pathname === href || pathname.startsWith(`${href}/`);
}

export function Navigation() {
  const [openKey, setOpenKey] = useState<string | null>(null);
  const pathname = usePathname();

  return (
    <nav aria-label="Primary" className="hidden lg:block">
      <ul className="flex items-center gap-0.5 xl:gap-1">
        {primaryNav.map((item) => {
          const hasChildren = !!item.children?.length;
          const isOpen = openKey === item.label;
          const isActive =
            isActiveHref(pathname, item.href) ||
            (item.children?.some((child) => isActiveHref(pathname, child.href)) ?? false);

          return (
            <li
              key={item.label}
              className="relative"
              onMouseEnter={() => hasChildren && setOpenKey(item.label)}
              onMouseLeave={() => hasChildren && setOpenKey(null)}
            >
              <Link
                href={item.href}
                className={cn(
                  "flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-2 text-[13px] font-semibold uppercase tracking-wide transition-colors hover:bg-pchs-green-900/5 hover:text-pchs-green-700",
                  isActive
                    ? "text-pchs-green-900 after:absolute after:inset-x-3 after:-bottom-[1px] after:h-0.5 after:rounded-full after:bg-pchs-gold-500 after:content-['']"
                    : "text-pchs-green-900/85",
                )}
                aria-haspopup={hasChildren || undefined}
                aria-expanded={hasChildren ? isOpen : undefined}
                aria-current={isActive ? "page" : undefined}
                onFocus={() => hasChildren && setOpenKey(item.label)}
              >
                {item.label}
                {hasChildren && (
                  <ChevronDownIcon className="h-3.5 w-3.5" aria-hidden="true" />
                )}
              </Link>

              {hasChildren && (
                <div
                  className={cn(
                    "absolute left-0 top-full z-40 min-w-56 rounded-lg border border-black/5 bg-white py-2 shadow-lg transition-all",
                    isOpen
                      ? "pointer-events-auto translate-y-0 opacity-100"
                      : "pointer-events-none -translate-y-1 opacity-0",
                  )}
                >
                  {item.children!.map((child) => (
                    <Link
                      key={child.href}
                      href={child.href}
                      className="block px-4 py-2 text-sm font-medium text-pchs-ink hover:bg-pchs-cream hover:text-pchs-green-700"
                    >
                      {child.label}
                    </Link>
                  ))}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
