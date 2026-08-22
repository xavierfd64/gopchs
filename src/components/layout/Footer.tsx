import Link from "next/link";
import Image from "next/image";
import { siteConfig, portals } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import { primaryNav } from "@/lib/navigation";

export function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="bg-pchs-green-950 text-white/80">
      <Container className="grid grid-cols-1 gap-10 py-14 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <div className="flex items-center gap-3">
            <Image
              src={siteConfig.logo}
              alt={`${siteConfig.schoolName} logo`}
              width={48}
              height={48}
              className="h-11 w-11"
            />
            <span>
              <span className="block font-display text-base font-extrabold text-white">
                Go PCHS
              </span>
              <span className="block text-xs text-white/60">
                {siteConfig.schoolName}
              </span>
            </span>
          </div>
          <p className="mt-4 text-sm leading-relaxed text-white/60">
            {siteConfig.shortTagline}
          </p>
        </div>

        <div>
          <h3 className="mb-4 text-sm font-bold uppercase tracking-wide text-pchs-gold-400">
            Explore
          </h3>
          <ul className="space-y-2 text-sm">
            {primaryNav
              .filter((item) => item.label !== "Home")
              .map((item) => (
                <li key={item.label}>
                  <Link href={item.href} className="hover:text-pchs-gold-300">
                    {item.label}
                  </Link>
                </li>
              ))}
          </ul>
        </div>

        <div>
          <h3 className="mb-4 text-sm font-bold uppercase tracking-wide text-pchs-gold-400">
            Go PCHS Portals
          </h3>
          <ul className="space-y-2 text-sm">
            {portals.slice(0, 5).map((portal) => (
              <li key={portal.key}>
                <Link href="/portals" className="hover:text-pchs-gold-300">
                  {portal.name}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h3 className="mb-4 text-sm font-bold uppercase tracking-wide text-pchs-gold-400">
            School Information
          </h3>
          <ul className="space-y-2 text-sm text-white/70">
            <li>DepEd School ID: {siteConfig.depedSchoolId}</li>
            <li>{siteConfig.region}</li>
            <li>{siteConfig.division}</li>
            <li>
              <Link href="/contact" className="hover:text-pchs-gold-300">
                Contact details &amp; map →
              </Link>
            </li>
          </ul>
        </div>
      </Container>

      <div className="border-t border-white/10">
        <Container className="flex flex-col items-center justify-between gap-2 py-5 text-xs text-white/50 sm:flex-row">
          <p>
            © {year} {siteConfig.schoolName}. All rights reserved.
          </p>
          <p>Go PCHS — {siteConfig.tagline}</p>
        </Container>
      </div>
    </footer>
  );
}
