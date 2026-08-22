import { siteConfig } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import { Button } from "@/components/ui/Button";
import { PortalQuickAccess } from "@/components/home/PortalQuickAccess";

export function Hero() {
  return (
    <section className="relative overflow-hidden bg-pchs-green-950 text-white">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-pchs-gold-500/20 blur-3xl"
      />

      <Container className="relative z-10 grid gap-10 py-14 sm:py-20 lg:grid-cols-2 lg:items-center lg:py-24">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.3em] text-pchs-gold-400">
            {siteConfig.schoolName} &middot; Est.{" "}
            {new Date(siteConfig.founded).getFullYear()}
          </p>
          <h1 className="mt-4 font-display text-4xl font-black leading-[1.05] sm:text-5xl lg:text-6xl">
            Building
            <span className="block">Champions</span>
            <span className="block text-pchs-gold-400">for Life!</span>
          </h1>
          <p className="mt-6 max-w-md text-base leading-relaxed text-white/75 sm:text-lg">
            Empowering learners today for a better tomorrow.{" "}
            {siteConfig.shortTagline}
          </p>
          <div className="mt-8 flex flex-wrap gap-4">
            <Button href="/about" size="lg">
              Learn More About PCHS
            </Button>
            <Button href="/portals" variant="outline" size="lg">
              Visit a Portal
            </Button>
          </div>
        </div>

        <div className="relative">
          <div className="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-pchs-green-700 via-pchs-green-800 to-pchs-green-950 shadow-2xl">
            <BuildingGlyph className="h-28 w-28 text-white/15 sm:h-36 sm:w-36" />
            <span className="absolute bottom-4 right-4 rounded-full bg-black/30 px-3 py-1 text-[11px] font-medium text-white/70">
              Campus photo placeholder
            </span>
          </div>
        </div>
      </Container>

      <PortalQuickAccess />
    </section>
  );
}

function BuildingGlyph(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 64" fill="none" aria-hidden="true" {...props}>
      <rect x="6" y="24" width="52" height="34" rx="1.5" fill="currentColor" />
      <rect x="2" y="52" width="60" height="6" rx="1" fill="currentColor" />
      <path d="M32 6 58 24H6L32 6Z" fill="currentColor" />
      {[12, 22, 32, 42].map((x) => (
        <rect key={x} x={x} y="32" width="8" height="10" rx="1" fill="#0a2417" />
      ))}
    </svg>
  );
}
