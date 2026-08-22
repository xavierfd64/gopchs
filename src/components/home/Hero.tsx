import Image from "next/image";
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
          <div className="relative aspect-[4/3] w-full overflow-hidden rounded-2xl border border-white/10 shadow-2xl">
            <Image
              src="/assets/images/hero/pchs-campus-hero.jpg"
              alt={`${siteConfig.schoolName} campus`}
              fill
              priority
              sizes="(min-width: 1024px) 50vw, 100vw"
              className="object-cover object-[80%_60%]"
            />
          </div>
        </div>
      </Container>

      <PortalQuickAccess />
    </section>
  );
}
