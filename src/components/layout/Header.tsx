import Image from "next/image";
import Link from "next/link";
import { siteConfig } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import { TopBar } from "@/components/layout/TopBar";
import { Navigation } from "@/components/layout/Navigation";
import { MobileNav } from "@/components/layout/MobileNav";
import { SearchIcon } from "@/components/ui/icons";

export function Header() {
  return (
    <header className="sticky top-0 z-30 bg-white shadow-sm">
      <TopBar />
      <Container className="flex items-center justify-between gap-4 py-2.5">
        <Link href="/" className="flex items-center gap-3">
          <Image
            src={siteConfig.logo}
            alt={`${siteConfig.schoolName} logo`}
            width={96}
            height={96}
            className="h-16 w-16 shrink-0 sm:h-20 sm:w-20"
            priority
          />
          <span className="leading-tight">
            <span className="block font-display text-2xl font-extrabold text-pchs-green-900 sm:text-3xl">
              <span className="text-pchs-gold-500">Go</span> PCHS
            </span>
            <span className="block text-xs font-bold uppercase tracking-wide text-pchs-ink/80 sm:text-sm">
              {siteConfig.schoolName}
            </span>
            <span className="block text-xs text-pchs-ink/50 sm:text-sm">
              Est. {new Date(siteConfig.founded).toLocaleDateString("en-US", {
                month: "long",
                day: "numeric",
                year: "numeric",
              })}
            </span>
          </span>
        </Link>

        <Navigation />

        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-label="Search the site"
            className="hidden h-10 w-10 items-center justify-center rounded-full text-pchs-green-900 hover:bg-pchs-cream lg:flex"
          >
            <SearchIcon className="h-5 w-5" />
          </button>
          <MobileNav />
        </div>
      </Container>
    </header>
  );
}
