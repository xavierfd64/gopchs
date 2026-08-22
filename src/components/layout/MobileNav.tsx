"use client";

import { useState } from "react";
import Link from "next/link";
import { primaryNav } from "@/lib/navigation";
import { MenuIcon, CloseIcon, ChevronDownIcon } from "@/components/ui/icons";
import { cn } from "@/lib/utils";

export function MobileNav() {
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);

  return (
    <div className="lg:hidden">
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Open menu"
        className="flex h-10 w-10 items-center justify-center rounded-md text-pchs-green-900"
      >
        <MenuIcon className="h-6 w-6" />
      </button>

      {open && (
        <div className="fixed inset-0 z-50 flex">
          <div
            className="absolute inset-0 bg-black/40"
            onClick={() => setOpen(false)}
            aria-hidden="true"
          />
          <div className="relative ml-auto flex h-full w-80 max-w-[85vw] flex-col overflow-y-auto bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-black/5 px-4 py-4">
              <span className="font-display text-lg font-bold text-pchs-green-900">
                Menu
              </span>
              <button
                type="button"
                onClick={() => setOpen(false)}
                aria-label="Close menu"
                className="flex h-9 w-9 items-center justify-center rounded-md text-pchs-green-900"
              >
                <CloseIcon className="h-5 w-5" />
              </button>
            </div>

            <nav aria-label="Mobile primary" className="flex-1 px-2 py-2">
              <ul>
                {primaryNav.map((item) => {
                  const hasChildren = !!item.children?.length;
                  const isExpanded = expanded === item.label;

                  return (
                    <li key={item.label} className="border-b border-black/5">
                      <div className="flex items-center justify-between">
                        <Link
                          href={item.href}
                          onClick={() => setOpen(false)}
                          className="flex-1 py-3 pl-2 text-sm font-semibold uppercase tracking-wide text-pchs-green-900"
                        >
                          {item.label}
                        </Link>
                        {hasChildren && (
                          <button
                            type="button"
                            onClick={() =>
                              setExpanded(isExpanded ? null : item.label)
                            }
                            aria-label={`Toggle ${item.label} submenu`}
                            aria-expanded={isExpanded}
                            className="flex h-10 w-10 items-center justify-center text-pchs-green-800"
                          >
                            <ChevronDownIcon
                              className={cn(
                                "h-4 w-4 transition-transform",
                                isExpanded && "rotate-180",
                              )}
                            />
                          </button>
                        )}
                      </div>
                      {hasChildren && isExpanded && (
                        <ul className="pb-2 pl-4">
                          {item.children!.map((child) => (
                            <li key={child.href}>
                              <Link
                                href={child.href}
                                onClick={() => setOpen(false)}
                                className="block py-2 text-sm text-black/70 hover:text-pchs-green-700"
                              >
                                {child.label}
                              </Link>
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ul>
            </nav>
          </div>
        </div>
      )}
    </div>
  );
}
